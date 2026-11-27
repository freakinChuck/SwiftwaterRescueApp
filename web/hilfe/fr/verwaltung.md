# Administration

Vous ouvrez l'administration avec ⚙ dans l'en-tête du menu. Elle fonctionne au mieux sur ordinateur. À gauche se trouvent les sections par catégorie (sur téléphone comme liste de sélection) ; vous ne voyez que les sections pour lesquelles vous avez un droit. À partir de neuf sections, le champ de recherche « Chercher une section » aide. Le bouton **?** en haut ouvre le mode d'emploi de la section choisie.

![Administration, section « Utilisateurs »](img/admin-benutzer.jpg)

En haut à droite : **Vers l'app d'intervention**, **Mes interventions** et le menu du compte (changer le mot de passe, se déconnecter, mentions légales, version).

Toutes les sections ont la même structure :

- Les entrées apparaissent comme cartes les unes sous les autres, les boutons toujours dans le même ordre : actions propres, **Modifier**, ↑/↓ pour l'ordre et en dernier **Retirer** ou **Désactiver**.
- En haut figure **+ Nouveau …**, pour les listes plus longues avec une recherche à côté.
- Les entrées désactivées sont repliées sous **Désactivé (N)**.
- Dans les réglages, **Enregistrer** enregistre toute la carte. Après une modification, « Modifications non enregistrées » apparaît à côté ; si vous changez de section, l'administration le demande. Ce qui est marqué « enregistré immédiatement » (p. ex. les fonctions par type d'intervention) vaut sans enregistrer.

> Les droits valent toujours aussi sur le serveur. Ce que l'administration masque ne peut pas non plus être modifié par un autre moyen.

## Personnes {#personen}

### Utilisateurs {#benutzer}

Créer des personnes, modifier nom et rôles d'utilisateur, réinitialiser le mot de passe et désactiver. Avec un nouveau compte ou un mot de passe réinitialisé, la personne doit définir son propre mot de passe à la prochaine connexion.

- **Désactiver** déconnecte la personne et la retire d'une intervention en cours. Ses données restent, elle peut être réactivée.
- **Supprimer** n'existe que pour les comptes qui ne se sont jamais connectés et n'apparaissent dans aucune intervention.
- **Supprimer définitivement** existe pour tous les autres comptes avec le droit spécial « Supprimer définitivement » (voir [Supprimer définitivement](#endgueltig)).
- Vous ne pouvez modifier que des personnes dont vous avez vous-même tous les droits.

### Rôles d'utilisateur {#benutzerrollen}

Les rôles regroupent des droits, p. ex. « Intervenant », « Responsable d'exercice » ou « Administrateur système ». Une personne peut avoir plusieurs rôles et reçoit tous leurs droits. Le rôle Administrateur système a toujours tous les droits et ne peut pas être modifié. Pour chaque rôle, vous définissez aussi quels groupes d'intervention il peut mobiliser.

![Rôles d'utilisateur](img/admin-benutzerrollen.jpg)

### Groupes d'intervention {#einsatzgruppen}

Groupes comme « Groupe bateau » ou « Groupe rive » avec couleur et membres. Au démarrage d'une intervention, on mobilise des groupes. Pour chaque type d'intervention, l'administration définit quels groupes sont mobilisables et présélectionnés. « Afficher la couleur à côté du nom » montre le groupe comme point à côté des noms dans l'administration.

![Groupes d'intervention](img/admin-einsatzgruppen.jpg)

### Absences {#abwesenheiten}

Absences en cours et planifiées de toutes les personnes (droit « Gérer les absences »). Les absents ne sont pas alarmés.

En dessous figurent les personnes qui, selon leurs [horaires de disponibilité réguliers](#profil/einsatzzeiten), ne sont actuellement pas disponibles, et toutes les règles. **+ Règle** saisit une règle pour une personne, **Modifier** et **Retirer** fonctionnent comme dans le profil. Le tout réuni dans le calendrier : **📅 Calendrier** dans l'en-tête ([Calendrier](#profil/kalender)).

### Formation {#ausbildung}

Catalogue des compétences (p. ex. « Sauveteur », « Pilote de bateau ») avec validité, préavis, obligation par groupe d'intervention et si la personne saisit elle-même la formation achevée. L'aperçu montre toutes les personnes avec l'état par compétence, filtrable par groupe d'intervention et « seulement avec action requise », avec export CSV.

## Intervention {#einsatz}

### Types d'intervention {#einsatztypen}

Les types d'interventions, p. ex. « Intervention », « Exercice » ou « Test de piquet ». Chaque type actif a son propre bouton de démarrage dans l'app d'intervention. Par type :

- nom, couleur, catégorie (primaire, secondaire, événement libre),
- **niveau d'alarme** (critique à aucun), niveau pour les messages et la fin d'intervention, son pour l'alarme critique,
- groupes d'intervention mobilisables et présélectionnés, personnes individuelles autorisées, visible pour les non-mobilisés,
- exercices préparés autorisés, présélection « Enregistrer ».

**Son pour l'alarme critique** (en haut de la liste) : valeur par défaut de l'organisation pour tous les types sans son propre, par défaut le klaxon deux tons. Dans le dialogue d'un type, « Valeur par défaut de l'organisation » s'applique jusqu'à ce que vous choisissiez un autre son ou « Son du système ». Chaque personne peut choisir son propre son dans le profil de l'app native ; l'app web joue toujours son propre son.

Un nouveau type reprend les réglages d'un type existant. Les types supprimés restent conservés avec leurs interventions.

![Types d'intervention](img/admin-einsatztypen.jpg)

### Options d'engagement {#einrueckoptionen}

Le choix lors de l'acceptation d'une intervention. Par option : partage la position, droits de la direction d'intervention, compte parmi les intervenants. Les options sont désactivées plutôt que supprimées ; au moins une reste active.

![Options d'engagement](img/admin-einrueckoptionen.jpg)

### Rôles spéciaux {#spezialrollen}

Rôles en intervention, p. ex. direction d'intervention, sauveteur ou poste de sécurité. Par rôle : ordre, couleur, **poste de sécurité** (limite la zone de recherche), peut envoyer des messages, peut parler à la radio.

![Rôles spéciaux](img/admin-spezialrollen.jpg)

### Intervenants externes {#externe}

Modèles pour intervenants externes (nom, description, rôle spécial, abréviation, couleur, éventuellement une position fixe). La direction d'intervention les choisit lors de la saisie en intervention.

### Symboles d'intervention {#symbole}

Quels symboles tactiques sont disponibles pour les marqueurs et si les marqueurs sans symbole sont autorisés.

### Concepts d'intervention {#konzepte}

Situations préparées pour des tronçons de cours d'eau (droit « Gérer les concepts d'intervention »). En haut, le **comportement par type d'intervention** : ce qui se passe quand un PLS se trouve dans le périmètre (Désactivé, Manuel uniquement, Proposer, Charger automatiquement) et si des concepts suivants sont proposés. Un concept peut déroger à ce comportement.

**Modifier** ouvre une boîte de dialogue avec carte :

- **Périmètre** : « Zone sur la carte » (placer les sommets) ou « Le long du cours d'eau de A à B » (placer A et B, « Calculer le tronçon » charge le réseau hydrographique ; « Marge » = distance maximale d'un PLS au cours d'eau).
- **Types d'intervention** concernés.
- **Éléments** : poste (symbole, rôle spécial comme mission, attribution à des personnes et groupes d'intervention, en option rôle spécial et véhicule), marqueur, zone, ligne, intervenant externe (aussi depuis un modèle), message à tous, documents, exclure ou prendre en compte un barrage, canaux radio, renfort de groupes d'intervention, activer les hélicoptères, démarrer l'enregistrement. « Présélectionné au chargement » définit les coches de l'aperçu. Les points se déplacent sur la carte.

**Reprendre d'une intervention …** fait un brouillon à partir des marqueurs, zones, lignes et externes d'une intervention ou d'un exercice. Un concept déjà chargé ne peut plus qu'être désactivé.

![Concepts d'intervention](img/admin-konzepte.jpg)
![Modifier un concept d'intervention](img/admin-konzept-dialog.jpg)

## Planifications {#planungen}

Exercices préparés, interventions secondaires planifiées et événements libres avec leurs éléments, plan d'exercices et inscriptions, exécutions avec journal et replay. Voir [Exercices et planifications](#planung/vorbereiten).

## Fonctions {#funktionen-kat}

### Fonctions {#funktionen}

Activables et désactivables par type d'intervention : zone de recherche, victimes, messages, marqueurs, externes, distance fluviale, vue publique, enregistrement, navigation, sites fixes, niveau & débit, info personne, couche de géodonnées, radio, véhicules. Les fonctions désactivées disparaissent immédiatement de l'app d'intervention, même en intervention en cours ; les données existantes sont conservées.

![Fonctions par type d'intervention](img/admin-funktionen.jpg)

### Distance fluviale {#flussdistanz}

Les vitesses de comparaison avec lesquelles l'outil Distance fluviale calcule les temps de trajet (1 à 6 valeurs).

### Navigation {#navigation}

Quelles apps de navigation sont disponibles et laquelle est la valeur par défaut.

### Temps dans l'eau {#wasserzeit}

Valeurs indicatives du temps dans l'eau en minutes par **protection thermique** (aucune, combinaison néoprène, combinaison étanche) et **température de l'eau** (paliers librement définissables). Les valeurs par défaut sont une proposition pour un travail actif dans le courant ; compare-les avec les documents de formation de l'organisation (**Insérer les valeurs par défaut** les rétablit). En outre le préavertissement en pour cent, le niveau des notifications et la détection automatique (distance à l'axe du cours d'eau, durée jusqu'à « dans l'eau » ou « à terre ») ainsi que le rayon pour la station de mesure avec température de l'eau. Par type d'intervention, tu actives ou désactives la fonction sous [Fonctions](#funktionen).

### Appel d'urgence (SOS) {#sos}

Qui est alerté lors d'un appel d'urgence : **direction d'intervention et participants dans le rayon** (rayon en km), **seulement la direction d'intervention** ou **tous les participants**. En outre, la durée d'appui sur le bouton (1–5 s), la durée du compte à rebours d'annulation (0–10 s, 0 = sans) et si un signal sonore retentit dans la conférence en direct. La fonction elle-même s'active et se désactive par type d'intervention sous **Fonctions**.

### Langue {#sprache}

L'app existe en allemand, anglais, français et italien. Ici, vous définissez la **valeur par défaut de l'organisation**. Elle vaut pour les personnes qui n'ont pas choisi de langue elles-mêmes et dont l'appareil n'utilise aucune de ces langues, ainsi que pour les notifications push aux personnes qui n'ont jamais ouvert l'app.

> Les textes de l'app sont traduits. Ce que l'organisation saisit elle-même (types d'intervention, rôles, options d'engagement, messages, documents) et le journal d'intervention restent dans la langue dans laquelle ils ont été rédigés.

### Assistant IA {#ai}

Fournisseur (compatible OpenAI ou Anthropic), modèle, clé, limites et protection des données par type d'intervention (remplacer les noms, arrondir les coordonnées). Dans la matrice, vous définissez par fonction, rôle et type d'intervention si l'assistant est désactivé, sur demande ou en accompagnement.

## Carte & données {#karte-daten}

### Fournisseurs de cartes {#karten}

Quelles cartes de base (images aériennes, cartes) sont disponibles, leur ordre et la valeur par défaut, globalement et par type d'intervention. « Tester » charge une tuile d'exemple.

![Fournisseurs de cartes](img/admin-karten.jpg)

### Coordonnées & unités {#koordinaten}

Le système de coordonnées par défaut et l'unité par défaut des vitesses (m/s, km/h ou les deux) pour la vitesse du courant, la distance fluviale et les hélicoptères. Chaque personne peut modifier les deux dans le profil. La vue publique affiche toujours le réglage par défaut.

### Sources de géodonnées {#geoquellen}

D'où vient le réseau hydrographique pour la zone de recherche et la distance fluviale : propre base PostGIS, API PostGIS et serveurs Overpass, dans un ordre réglable, avec cache et statistique par serveur.

### Hélicoptères {#helikopter}

Sources des données ADS-B, filtre (hélicoptères de sauvetage, hélicoptères, tous), qui voit les hélicoptères, rayon avec et sans intervention, liste des hélicoptères de sauvetage.

### Niveau & débit {#pegel}

Rayon et nombre de stations de mesure au PLS, lacs oui ou non, sources de données avec test.

### Sites fixes {#standorte}

Lieux, zones et lignes qui figurent sur la carte dans chaque intervention, p. ex. mises à l'eau, dépôts ou chenaux. Avec catégories (affichées ou masquées par défaut, prioritaires), propriétés (p. ex. « barrière fluviale », qui limite la zone de recherche, ou un avertissement) et points de navigation (accès).

![Sites fixes](img/admin-standorte.jpg)

### Reprendre des géodonnées {#geodaten}

Chercher sur une carte des objets au bord de l'eau issus d'OpenStreetMap (barrages, centrales, ponts, cales de mise à l'eau …) et les reprendre comme sites fixes. L'app compare régulièrement les sites repris avec la source et signale les écarts.

### Véhicules {#fahrzeuge}

Types de véhicules (bateau, remorque, véhicule tracteur) avec places et chargement, véhicules avec leur balise (UUID, major, minor, éventuellement l'adresse MAC pour le niveau de batterie sous iOS ; les appareils Android l'apprennent eux-mêmes) et le dernier niveau de batterie annoncé, définir la position manuellement et l'assemblage (quel véhicule porte quoi).

## Évaluation {#auswertung-kat}

### Statistique et journal d'intervention {#einsatzlog}

Indicateurs et toutes les interventions avec journal, replay, after action review, anonymisation et « Supprimer l'enregistrement ». Voir [Évaluation](#auswertung/statistik).

### Configurer l'after action review {#aarcfg}

Titre, logo, champs du review, sections fournies, documents autorisés par état et le feedback d'intervention avec ses questions.

### Statistique par personne {#personenstatistik}

Évaluation par personne avec filtre, diagrammes et CSV.

### Consultation pour les intervenants {#einsicht}

Ce que les participants voient sous « Mes interventions » : désactivé, anonyme (par défaut) ou complet, et quels boutons (journal, replay, AAR, Word, PDF).

### Anonymisation {#anonymisierung}

Retirer par étapes les données personnelles des interventions terminées : anonymiser l'affichage, supprimer les enregistrements vocaux, replay, journal, textes libres, détacher les participations du compte, supprimer l'enregistrement. Par étape et type d'intervention, un délai pour l'exécution automatique. À part l'affichage, les étapes sont irréversibles.

![Anonymisation](img/admin-anonymisierung.jpg)

## Informations légales {#rechtliches}

Informations de l'organisation pour les mentions légales et la déclaration de protection des données. La page « Mentions légales, licences & protection des données » les complète avec l'état actuel des réglages.

### Extrait de protection des données {#datenschutzauszug}

Pour les demandes d'accès selon la loi sur la protection des données. Deux droits séparés, tous deux dans le groupe « Protection des données » des rôles d'utilisateur ; l'administrateur système les a toujours.

- **Extrait par personne** (droit « Créer un extrait de protection des données par personne ») : choisir la personne (aussi désactivée), puis « Créer un PDF » ou « Créer un ZIP ». Le PDF contient compte et réglages, rôles et groupes, appareils connectés, absences, horaires de disponibilité, formation, planifications, participations avec dernière position, entrées du journal d'intervention, messages envoyés et reçus, annonces, feedback d'intervention, éléments saisis, utilisation de l'assistant IA et le journal de l'application. Le ZIP contient en plus toutes les données en JSON et CSV, l'historique des positions en GeoJSON, la photo de profil et les propres enregistrements vocaux.
- **Export des données de l'organisation** (droit « Créer l'export des données de l'organisation ») : « Aperçu en PDF » liste toutes les tables avec le nombre de lignes et toutes les personnes ; « Créer un ZIP » contient par table un fichier JSON et un fichier CSV, sur demande aussi les enregistrements vocaux et les after action reviews sauvegardés.

> Les mots de passe, clés et jetons d'accès ne sont jamais inclus. L'extrait est créé dans le navigateur et n'est enregistré nulle part ; chaque extrait créé figure dans l'**historique** (qui, quand, quelle personne, quel format).

> ! Un extrait contient des noms, des positions et des contenus. Ne le transmettre que de manière sûre à la personne autorisée.

Les interventions dont les participations ont été anonymisées et détachées du compte ne sont plus attribuées à la personne et manquent donc dans l'extrait.

## Documents {#dokumente-kat}

La gestion documentaire tient à disposition la documentation nécessaire en intervention, en exercice et en formation : plans d'intervention, check-lists, notices, règlements. Les personnes avec le droit « Gérer la gestion documentaire » voient tout ce qui est nécessaire. Qui fait partie de la **rédaction** d'un dossier ne voit le classement et la boîte de réception que pour ses dossiers. La fonction « Documents » peut être désactivée par type d'intervention sous [Fonctions](#funktionen).

### Classement {#dokumente}

À gauche figure l'**arborescence des dossiers** avec le nombre d'entrées par dossier (sous-dossiers compris), ainsi que « Tous les documents » et « Révision due ». 🔒 marque les dossiers que tout le monde ne voit pas. À droite figure en haut la carte du dossier choisi avec ses **accès** sur une ligne (qui le voit, rédaction, propositions, pour combien de personnes il est visible), en dessous ses entrées comme cartes : type (PDF, TEXTE, LIEN …), version, hors ligne, images, confirmation de lecture, « Révision due », fichier ou adresse, où il apparaît en intervention et la date.

- **+ Entrée** ouvre le dialogue avec les onglets Fichier, Page et Lien ; **Téléverser …** crée plusieurs fichiers à la fois dans le dossier choisi (titre = nom du fichier) ; **+ Dossier** crée un sous-dossier du dossier choisi.
- **Glisser** : glisser des entrées dans un autre dossier les déplace ; les dossiers (avec le droit « Gérer la gestion documentaire ») peuvent être glissés dans un autre dossier ou sur « Tous les documents » (niveau supérieur). L'appareil mémorise les dossiers dépliés.
- **Recherche** « Chercher dans tous les dossiers … » trouve titres, mots-clés, descriptions et le texte des pages et PDF, avec l'extrait.

Une entrée est un **fichier** (avec versions), une **page** (texte directement dans l'app, Markdown comme dans ce mode d'emploi, avec images) ou un **lien**. Par entrée : titre, description, mots-clés, types d'intervention (« Pour cette intervention »), sites fixes (le document apparaît dans la popup du site), « Réviser avant le », hors ligne et confirmation de lecture.

- **Dossier** (« Modifier le dossier ») : à gauche nom, dossier parent, description et qui le voit (tous les connectés ou seulement certains groupes d'intervention ou rôles d'utilisateur, avec le nombre de personnes) ; un sous-dossier ne peut que restreindre davantage. À droite la **rédaction** (peut créer et modifier des entrées), qui peut **proposer** et si le dossier est **partageable avec des externes**.
- **Entrée** (« Modifier ») : à gauche type, titre, dossier, mots-clés, description et le contenu (fichier avec versions, texte de la page ou adresse), à droite « Proposer en intervention » (types d'intervention et sites fixes via « + Site »), « Réviser avant le », confirmation de lecture (« Configurer … », « Voir l'état ») et hors ligne (« peut être enregistré hors ligne », « enregistrer automatiquement hors ligne pour tous les lecteurs »). Désactiver et activer directement sur la carte.
- **Versions** : un nouveau fichier remplace le précédent. Les versions antérieures sont conservées selon les [paramètres](#dokumente-einstellungen) et peuvent être restaurées. Le texte des PDF est extrait au téléversement pour la recherche.
- **Confirmation de lecture** : par tous ceux qui voient l'entrée, seulement par certains groupes d'intervention ou rôles d'utilisateur, ou comme l'obligation d'une compétence de la [formation](#ausbildung). Les personnes arrivant plus tard doivent, au choix, aussi confirmer (avec leur propre délai dès l'arrivée) ou non. Délai en jours avec rappel par push. Une nouvelle version exige de nouveau la confirmation, au choix seulement pour des modifications importantes. Avec une compétence, la confirmation inscrit la formation chez la personne. L'aperçu montre les confirmations ouvertes, confirmées et volontaires, avec « Rappeler » et CSV.

> Qui peut voir une entrée est réglé par le dossier. Qui doit la confirmer est réglé par l'entrée. Tous les autres lecteurs peuvent confirmer volontairement.

### Boîte de réception {#dokumente-eingang}

Propositions d'intervenants : une nouvelle entrée, une nouvelle version ou une remarque (« Radio manquante sur la check-list »). Seule la rédaction du dossier les voit. **Reprendre** crée l'entrée ou la version, **Refuser** avec motif, les remarques sont marquées comme **traitées**. La personne qui propose est notifiée (push) et voit l'état dans le profil.

### Affichage {#dokumente-anzeige}

Quelles sections apparaissent dans la fenêtre « Documents » de l'app d'intervention et dans quel ordre : confirmation de lecture ouverte, favoris, sur des sites de la zone d'intervention, pour cette intervention, ouverts récemment, nouveaux et modifiés, dossiers. Il existe une valeur par défaut (aussi sans intervention) et, sur demande, une propre par type d'intervention. En plus le nombre d'entrées par section, le nombre dans la carte de menu « Documentation » et si les personnes peuvent disposer les sections pour elles-mêmes.

### Paramètres {#dokumente-einstellungen}

- **Types de fichiers** : catalogue de types fixes, chacun activable, plus des extensions libres. L'app n'affiche jamais les extensions libres, elles sont seulement téléchargées. Les fichiers exécutables et actifs (p. ex. `.exe`, `.js`, `.html`, `.svg`) ne sont jamais autorisés. Le serveur vérifie le contenu, pas seulement l'extension.
- **Stockage** : taille maximale par fichier, stockage total, nombre de versions antérieures, hors ligne par appareil.
- **Externes** : si la direction d'intervention peut transmettre des documents aux externes, sur la page invité de la radio et/ou via un lien propre, si les externes peuvent télécharger et combien d'accès par heure sont autorisés.

## Connectivité {#konnektivitaet}

### Alarme par e-mail {#emailalarm}

Un e-mail entrant démarre une intervention : boîte aux lettres (IMAP), relève, expéditeurs autorisés et mots-clés par type d'intervention, mobilisation et PLS à partir de coordonnées dans l'e-mail. « Vérifier un exemple » teste un e-mail sans démarrer d'intervention.

### Radio & conférence {#funk}

Annonces et conférence en direct (serveur LiveKit), canaux avec droits d'écoute et de parole, transcription, conservation et liens pour les externes par type d'intervention.

### Organisations partenaires {#partner}

Conventions avec d'autres organisations qui exploitent leur **propre installation** avec leur propre adresse (droit « Configurer la connectivité »). Il n'y a pas de base de données commune : chaque organisation garde ses personnes, réglages et interventions sur son serveur et décide elle-même de ce qu'un partenaire peut faire.

1. Un côté saisit le partenaire avec **+ Organisation partenaire** : nom, adresse de l'installation (p. ex. `https://partner.example.ch/swiftwater/`, figure chez le partenaire en haut sous « Cette installation ») et **Générer une nouvelle clé**. La clé apparaît une fois pour être copiée.
2. L'autre côté saisit cette installation de la même manière et indique sous « Clé » la **clé reçue**. Transmettre la clé par un moyen sûr (pas par e-mail ordinaire) ; elle autorise la mobilisation.
3. **Tester la connexion** des deux côtés ; ensuite chaque côté connaît les types d'intervention du partenaire.
4. Dans le dialogue du partenaire, définir **quels groupes d'intervention** il peut mobiliser (tous ou une sélection) et **quel type d'intervention propre** suit un type d'intervention du partenaire. Les types d'intervention sans attribution ne déclenchent rien chez nous.

Les serveurs communiquent directement ; chaque requête est signée avec la clé et n'est valable que brièvement (les horloges des deux serveurs doivent être justes). **Désactiver** bloque le partenaire immédiatement sans supprimer la convention ; **Retirer** n'est possible que tant qu'aucune intervention commune n'est en cours. Une nouvelle clé remplace l'ancienne, le partenaire doit alors aussi la saisir. Déroulement en intervention : voir [Mobilisation et mobilisation complémentaire](#leitung/aufgebot).

> Les partenaires ne voient de nous que ce dont une intervention commune a besoin : nom et rôle spécial des participants avec position, la situation, les sites fixes et véhicules de cette intervention, les victimes communes et le canal commun. Les numéros de téléphone seulement si l'appel direct est ouvert chez nous à tous les participants.

## Système {#system}

### Journal de l'application {#applog}

Protocole technique du serveur et des appareils : événements, avertissements et erreurs, avec filtre, recherche et export. Aide à la recherche d'erreurs ; il ne contient ni mots de passe, ni positions, ni contenus d'intervention.

![Journal de l'application](img/admin-applog.jpg)

### Versions {#versionen}

Version du serveur, version minimale des apps et toutes les apps connectées par plateforme et version.

### Supprimer définitivement {#endgueltig}

Certaines choses ne peuvent normalement qu'être désactivées ou pas du tout supprimées, afin que les données d'intervention restent complètes. Qui a, en plus du droit de la section, le droit spécial **« Supprimer définitivement »** (rôles d'utilisateur, groupe Système) y voit le bouton **« Supprimer définitivement »** :

| Quoi | Où | Conséquences |
|---|---|---|
| Intervention terminée | Statistique d'intervention (droit « Évaluer toutes les interventions ») | Intervention avec journal, messages, enregistrement, victimes, marqueurs, annonces, after action review (aussi PDF sauvegardé) et feedback ; un exercice préparé est réinitialisé |
| Utilisateur | Utilisateurs | Compte et toutes les données personnelles ; dans les interventions passées, la personne apparaît comme « Personne supprimée », aussi dans le journal et les messages |
| Option d'engagement | Options d'engagement | les participations passées n'affichent plus que l'abréviation ; au moins une reste active par catégorie |
| Rôle spécial | Rôles spéciaux | les intervenants passés, modèles, éléments d'exercice et canaux radio perdent le rôle |
| Modèle | Intervenants externes | les externes déjà saisis restent |
| Compétence avec entrées | Formation | les formations saisies de toutes les personnes |
| Type de véhicule avec véhicules, véhicule issu d'interventions | Véhicules | les véhicules avec observations et assemblages, aussi dans le replay |
| Concept d'intervention | Concepts d'intervention | concept avec ses éléments ; les marqueurs chargés et le journal restent dans les interventions |

Avant la suppression, une confirmation énumère les conséquences. **Cela ne peut pas être annulé.** Les interventions en cours et votre propre compte ne peuvent pas être supprimés. Chaque suppression définitive figure dans le journal de l'application (sans noms).

> ! Pour les données personnelles en intervention, l'[anonymisation](#anonymisierung) est généralement la meilleure voie : elle conserve la statistique et le journal d'intervention.
