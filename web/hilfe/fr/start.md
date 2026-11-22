# Premiers pas

Swiftwater Rescue aide les groupes d'intervention de sauvetage aquatique pour l'alarme, la coordination et la vue d'ensemble de la situation lorsqu'une personne est disparue dans un cours d'eau, ainsi que lors d'exercices. L'app fonctionne dans le navigateur sur téléphone et ordinateur. Pour Android et iOS, il existe en plus une app propre (voir [App native](#app)).

Ce mode d'emploi décrit toutes les fonctions. Celles que vous voyez réellement dépendent de vos droits et des paramètres de votre organisation : les fonctions désactivées manquent entièrement dans l'app.

> Partout dans l'app se trouve le bouton **?**. Il ouvre le mode d'emploi directement à la section correspondante.

## Se connecter {#anmelden}

Vous recevez votre compte de l'administration de votre organisation : nom d'utilisateur et un premier mot de passe. À la première connexion, vous devez définir votre propre mot de passe. Ensuite, vous restez connecté sur l'appareil jusqu'à la déconnexion.

![Connexion](img/anmeldung.jpg)

Sous le bouton « Se connecter », vous trouvez :

- **Essayer la démo** : découvrir l'app sans compte (voir ci-dessous),
- **Mode d'emploi** : cette page,
- **Mentions légales, licences, protection des données** de l'organisation,
- le choix de la **langue** (« Automatique » utilise la langue de l'appareil).

Mot de passe oublié ? L'administration le réinitialise. Vous recevez à nouveau un premier mot de passe et définissez ensuite le vôtre.

## Mode démo {#demo}

En mode démo, vous essayez l'app d'intervention sans risque. Tout se passe uniquement dans cet onglet : rien n'est enregistré sur le serveur, personne n'est alarmé, et vos vraies interventions restent intactes. Si vous fermez l'onglet, l'état de la démo disparaît.

![Accueil du mode démo](img/demo-start.jpg)

- **Avec données d'exemple** : une intervention sur la Reuss à Lucerne est déjà en cours. Des intervenants simulés acceptent l'intervention, arrivent, envoient des messages et trouvent la victime.
- **Sans données** : app vide, vous démarrez vous-même une intervention ou un exercice.
- Avec la coche **Droits de la direction d'intervention**, vous voyez après l'acceptation toutes les fonctions de la direction d'intervention.

Le signe violet **DEMO** en haut à gauche rouvre ce dialogue à tout moment, p. ex. pour recommencer ou quitter la démo. Les images de ce mode d'emploi proviennent du mode démo.

## Installer l'app {#installieren}

L'app web peut être placée sur l'écran d'accueil comme une app. Elle s'ouvre alors sans barre d'adresse et peut recevoir des notifications push.

- **Android (Chrome)** : menu ⋮ → « Installer l'application » ou « Ajouter à l'écran d'accueil ». Le profil propose aussi le bouton « Installer l'app ».
- **iPhone et iPad (Safari)** : symbole Partager → « Sur l'écran d'accueil ». Sur iPhone, le push ne fonctionne qu'ainsi (dès iOS 16.4).
- **Ordinateur** : dans Chrome et Edge, un symbole d'installation apparaît dans la barre d'adresse.

Pour des alarmes fiables même avec le téléphone en silencieux, nous recommandons l'[app native](#app).

## Notifications push {#push}

Pour qu'une alarme vous parvienne même avec l'app fermée, il faut les notifications push. Tant que le push est désactivé sur votre appareil, un avis avec **Activer le push** figure en haut du menu. Vous pouvez aussi activer et désactiver le push dans le [profil](#profil/alarmierung). « Plus tard » masque l'avis pendant sept jours.

> ! L'app web ne peut pas contourner « Ne pas déranger » ni le mode silencieux. Pour les alarmes critiques qui sonnent aussi la nuit, utilisez l'[app native](#app/kritisch).

## L'interface {#oberflaeche}

L'app d'intervention affiche la carte en plein écran. Toutes les commandes se trouvent par-dessus :

- **☰ Menu** en haut à gauche : intervention, participation, direction, journal et outils (voir [Le menu](#einsatz/menu)).
- **Pastille d'intervention** en haut au centre : l'intervention en cours. La toucher ouvre le menu.
- **Affichage** en haut à droite (symbole des couches) : carte de base et ce qui apparaît sur la carte (voir [Affichage](#karte/ansicht)).
- **Boutons de carte** en bas à droite : cadrage, intervenants, victimes, messages, marqueurs, stations de mesure, radio et distance fluviale. Chacun ouvre une fenêtre latérale.
- **Avis sur la zone de recherche** en bas à gauche.

![Carte en intervention avec les boutons de carte](img/karte.jpg)

Dans l'en-tête du menu, vous trouvez :

| Bouton | Signification |
|---|---|
| 👤 | Profil : vos paramètres personnels |
| 📋 | Mes interventions : journal et replay de vos interventions terminées |
| ⚙ | Administration (seulement avec les droits correspondants) |
| ? | Ce mode d'emploi |
| ✕ | Fermer le menu |

## Sans connexion {#offline}

Après chaque actualisation, l'app mémorise le dernier état. Si la connexion tombe, elle affiche en haut un avis avec l'heure de la dernière connexion et continue de travailler avec cet état. Des actions comme l'envoi de messages échouent alors avec « Pas de connexion au serveur ». Dès le retour de la connexion, l'app recharge tout. Les secteurs de carte déjà vus restent visibles hors ligne.

Si une nouvelle version a été installée, « Nouvelle version disponible » apparaît en bas avec le bouton **Recharger**.
