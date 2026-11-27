# App native (Android et iOS)

L'app native « Swiftwater Rescue » pour Android et iOS offre la même app d'intervention que le navigateur, et en plus :

- **alarmes critiques** en plein écran avec sonnerie, même avec « Ne pas déranger » et un téléphone en silencieux,
- **partage de la position en arrière-plan**, même écran verrouillé,
- **plusieurs organisations** (serveurs) dans une seule app,
- **véhicules** via des balises Bluetooth.

L'app ouvre l'administration, « Mes interventions » et ce mode d'emploi comme page web ; vous n'avez pas besoin de vous reconnecter pour cela.

## Comptes {#konten}

Au premier démarrage, vous ajoutez un compte : adresse du serveur, nom d'utilisateur et mot de passe, éventuellement une désignation propre. Vous ajoutez d'autres comptes (autres organisations) dans la liste des comptes. Pour chaque compte, vous activez s'il est surveillé (« actif »).

- Au démarrage, l'app ouvre le dernier compte utilisé.
- Retour à la liste des comptes : toucher le nom du serveur avec ⇄ dans l'en-tête du menu ou « Changer de compte / serveur » dans le profil.
- La liste des comptes indique pour chaque compte si la position a pu être partagée en dernier.
- Avec **Essayer la démo** dans la liste des comptes, vous démarrez le [mode démo](#start/demo) aussi dans l'app.

## Langue {#sprache}

Sous **Réglages** (liste des comptes) et dans le profil, carte « Affichage », vous choisissez la langue de l'app : allemand, anglais, français, italien ou « Automatique » (langue de l'appareil). Elle vaut pour tous les comptes et pour les notifications ; l'app la communique aux serveurs pour que le push arrive dans la même langue.

## Alarme et surveillance {#hintergrund}

L'app interroge régulièrement tous les comptes actifs et signale les alarmes, les messages qui vous sont adressés, les annonces et la fin de l'intervention. Avec le push configuré, les notifications arrivent immédiatement même app fermée.

- **Android** : sans push ou pendant une intervention, un service d'arrière-plan fonctionne avec une notification permanente. « Toujours interroger en arrière-plan » dans le profil le garde actif en permanence.
- **iOS** : les notifications app fermée n'arrivent que par push.

## Alarmes critiques {#kritisch}

Pour les types d'intervention au niveau d'alarme « critique », l'app affiche l'alarme en plein écran et joue une sonnerie jusqu'à ce que vous l'acquittiez (au maximum 5 minutes).

- **Android** : autorisez l'app à accéder à **« Ne pas déranger »** pour que l'alarme sonne même dans ce cas. L'app le demande.
- **iOS** : les notifications critiques nécessitent l'autorisation « Alertes critiques ».
- Vous choisissez le son dans le profil sous « Alarme » par type d'intervention (cornes, sirènes, mélodies) et l'écoutez avec « Essai ».

## Position en arrière-plan {#standort}

Pour que l'app partage votre position en intervention même écran verrouillé, elle a besoin de l'autorisation de localisation **« Toujours autoriser »**. L'app la demande après l'acceptation d'une intervention. Sans cette autorisation, elle ne partage la position que tant qu'elle est ouverte.

Après la fin de l'intervention ou si vous quittez l'intervention, l'app cesse de partager la position.

## Radio {#funk}

Les annonces et la conférence en direct fonctionnent dans l'app comme dans le navigateur (voir [Radio](#einsatz/funk)). L'app a besoin pour cela de l'autorisation du microphone. Sous Android, la conférence continue même écran verrouillé.

## Véhicules et balises {#beacons}

En intervention avec des véhicules, l'app demande une fois l'accès au Bluetooth. Elle reconnaît les balises des véhicules à proximité, annonce leur position et vous attribue au véhicule dans lequel vous êtes assis. Hors des interventions, elle n'annonce les balises qu'avec votre accord dans le profil.

**Scanner les véhicules maintenant** (dans la fenêtre « Intervenants » sous « Véhicules » et dans le profil, carte « Véhicules ») cherche immédiatement les balises à proximité, affiche les véhicules trouvés avec la force du signal et les annonce : en intervention avec votre position, sinon sans votre nom (seulement si l'administration autorise les annonces hors des interventions). Pratique pour vérifier qu'une balise fonctionne.

**Niveau de batterie :** les balises DX-Smart l'envoient si la trame « Device Info » est activée dans leur app de configuration (laisser la trame « iBeacon » activée). Les balises avec Eddystone-TLM fonctionnent aussi : elles envoient la tension, l'app en estime le pourcentage (3,0 V = plein, 2,0 V = vide). Sous iOS, la trame « Device Info » est en plus nécessaire. L'app le lit à chaque scan ; il figure au véhicule (dès 20 % avec « ⚠ Batterie faible ») et dans l'aperçu de « Scanner les véhicules maintenant ». Y apparaissent aussi les balises à proximité qui n'appartiennent à aucun véhicule, avec leur adresse MAC. Sous iOS, l'app n'attribue le niveau de batterie que via l'adresse MAC du véhicule : un appareil Android l'apprend au premier scan, sinon l'administration la saisit au véhicule.

> ! Sur iPhone, l'app ne trouve les balises qu'avec la **Position exacte** (Réglages → Swiftwater Rescue → Position). Si elle est désactivée, le profil l'indique sous « Véhicules », avec le bouton **Autoriser la position exacte**. Hors des interventions, « Toujours » est en plus nécessaire.

## Mises à jour {#updates}

Si la version de votre app est trop ancienne pour le serveur, un avis apparaît avec **Mettre à jour** (Google Play ou App Store). **Nouveautés** dans le profil montre les changements.
