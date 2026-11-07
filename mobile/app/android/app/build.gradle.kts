import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Signierschlüssel für Releases: android/key.properties (nie einchecken) oder Umgebungsvariablen der Pipeline.
// Ohne Schlüssel wird mit dem Debug-Schlüssel signiert (installierbar, aber nicht für den Play Store).
val keyProps = Properties().apply {
    val f = rootProject.file("key.properties")
    if (f.exists()) f.inputStream().use { load(it) }
}
fun keyVal(k: String, env: String): String? = keyProps.getProperty(k) ?: System.getenv(env)?.takeIf { it.isNotEmpty() }
val storeFilePath = keyVal("storeFile", "ANDROID_KEYSTORE_PATH")

android {
    namespace = "ch.swiftwaterrescue.app"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
        isCoreLibraryDesugaringEnabled = true // flutter_local_notifications
    }

    defaultConfig {
        applicationId = "ch.swiftwaterrescue.app"
        minSdk = maxOf(flutter.minSdkVersion, 24)
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (storeFilePath != null) {
            create("release") {
                storeFile = file(storeFilePath)
                storePassword = keyVal("storePassword", "ANDROID_KEYSTORE_PASSWORD")
                keyAlias = keyVal("keyAlias", "ANDROID_KEY_ALIAS")
                keyPassword = keyVal("keyPassword", "ANDROID_KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            signingConfig = if (storeFilePath != null) signingConfigs.getByName("release") else signingConfigs.getByName("debug")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
}

flutter {
    source = "../.."
}
