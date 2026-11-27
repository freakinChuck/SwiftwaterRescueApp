# En intervention

## Le menu {#menu}

Le menu ☰ affiche en haut l'intervention : message, heure d'alarme, niveau d'alarme, PLS, vitesse du courant et si l'intervention est enregistrée ou visible publiquement. En dessous se trouvent des onglets :

| Onglet | Contenu |
|---|---|
| Intervention | Participation, Mon intervention, vitesse du courant, niveau & débit, outils, assistant IA |
| Direction | seulement pour la direction d'intervention, voir [Direction d'intervention](#leitung) |
| Exercice | seulement pour la direction d'exercice, voir [Exercices](#planung/freigabe) |
| Journal | Journal d'intervention en direct |
| Info | vos droits dans cette intervention, personne, serveur, version |

![Onglet « Intervention »](img/menu-einsatz.jpg)
![Onglet « Info » avec vos propres droits](img/menu-info.jpg)

À l'ouverture, le menu est toujours sur « Intervention » (pour la direction d'exercice sur « Exercice »).

## Intervenants {#einsatzkraefte}

Le bouton de carte **Intervenants** ouvre la liste de tous les participants, groupés par rôle spécial, suivis des intervenants externes et des véhicules. Pour chaque personne, vous voyez l'option d'engagement, l'âge de la position et les coordonnées.

![Liste des intervenants](img/einsatzkraefte.jpg)

- **Centrer** déplace la carte vers la personne, **Suivre** la garde dans l'image.
- **ℹ Info** affiche photo de profil, rôle spécial, option d'engagement et groupes d'intervention. La même fenêtre s'ouvre en touchant un intervenant sur la carte.
- 🧭 transmet la position à votre [app de navigation](#einsatz/navigation).
- Les groupes se déplient et se replient ; l'appareil s'en souvient.

La direction d'intervention peut ici modifier les rôles spéciaux, retirer des personnes de l'intervention et saisir des [intervenants externes](#leitung/externe).

## Messages {#nachrichten}

Le bouton de carte **Messages** ouvre l'historique. Tous ceux qui ont accepté l'intervention voient tous les messages. Les destinataires déterminent seulement qui est notifié ; les messages qui vous sont adressés sont marqués. Le chiffre rouge sur le bouton indique les non-lus.

![Messages](img/nachrichten.jpg)

1. Saisir le texte.
2. Choisir éventuellement des destinataires (groupés par rôle spécial). Sans sélection, tout le monde est notifié.
3. Éventuellement **Comme marqueur sur la carte** : à votre propre position ou à un point que vous touchez ensuite sur la carte.
4. **Envoyer**.

Les messages enregistrent votre position si vous la partagez. Les marqueurs de messages apparaissent comme catégorie propre sur la carte ; ils peuvent être retirés par l'expéditeur ou la direction d'intervention. Qui peut envoyer est défini par l'administration pour chaque rôle spécial.

## Victimes {#rettlinge}

Le bouton de carte **Victimes** affiche les personnes recherchées en trois groupes : **Disparue** (sans position), **Position connue** et **Sauvée**. Le chiffre rouge compte celles qui ne sont pas encore sauvées.

![Victimes](img/rettlinge.jpg)

- **+ Saisir une victime** : nom et description. Sans nom, elle s'appelle « Victime inconnue ».
- **📍 Lieu de découverte** : à votre propre position ou choisi sur la carte. Le dernier lieu annoncé fait foi.
- **Sauvée** ou **Annuler**.
- **Zone de recherche** (pour les disparues) : cadrage sur sa sous-zone.
- **⋯** déplie d'autres boutons : réinitialiser le lieu de découverte, **Définir le PLS** (PLS propre à cette victime), modifier et, pour la direction d'intervention, retirer.

Tous ceux qui ont accepté l'intervention peuvent saisir des victimes, annoncer le lieu de découverte et les annoncer sauvées.

## Marqueurs {#markierungen}

Le bouton de carte **Marqueurs** affiche trois groupes :

- **Point last seen (PLS)** : le PLS de l'intervention et ceux des victimes. Les PLS pâles ne comptent plus pour la zone de recherche (victime retrouvée).
- **Situation** : marqueurs, zones et lignes de la direction d'intervention.
- **Sites fixes** : lieux de l'organisation comme mises à l'eau ou dépôts ; avec une zone de recherche, répartis en « Dans la zone d'intervention » et « Hors ».

![Marqueurs](img/markierungen.jpg)

Avec les coches, vous masquez des entrées individuelles ou des groupes entiers sur la carte ; **Afficher** y déplace la carte. Les nouveaux marqueurs, zones et lignes sont placés par la direction d'intervention (voir [Dessiner la situation](#leitung/lage)).

## Vitesse du courant {#fliessgeschwindigkeit}

Dans l'onglet « Intervention », tous les participants définissent la **vitesse du courant** en m/s. L'app en calcule la longueur de la zone de recherche. Les stations de mesure au PLS (voir ci-dessous) aident à l'estimer.

## Temps dans l'eau {#wasserzeit}

Le **temps dans l'eau** surveille combien de temps une personne reste dans l'eau (nageurs, équipage de bateau dans l'eau), comme la surveillance des porteurs d'appareils respiratoires. Ainsi, personne ne reste trop longtemps dans l'eau froide sans que cela soit remarqué.

1. Dans l'onglet « Intervention », carte **Temps dans l'eau**, vérifie ta **protection thermique** (aucune, combinaison néoprène, combinaison étanche). Est présélectionnée ta valeur par défaut du profil (carte « Temps dans l'eau »), sinon la dernière utilisée, sinon celle de l'organisation. Tu peux la modifier à tout moment, aussi à terre.
2. Touche **🌊 Je vais dans l'eau**. Le temps tourne et s'affiche en haut de la carte avec le bouton **À terre**.
3. De retour à terre : touche **Retour à terre**.

La **valeur indicative** dépend de la protection thermique et de la température de l'eau. La température provient de la station de mesure de l'OFEV la plus proche ; la direction d'intervention peut la saisir à la main. Sans température, la valeur indicative du palier le plus froid s'applique.

Le temps s'affiche près de la personne dans la fenêtre « Intervenants », dans l'info personne et sous forme de pastille sous son marqueur sur la carte : **bleu** en cours, **jaune** dès le préavertissement (par défaut 75 %), **rouge** dès la valeur indicative. Au préavertissement et lorsque la valeur indicative est atteinte, toi et la direction d'intervention recevez une notification ; les deux figurent dans le journal d'intervention.

> Selon les réglages, l'app détecte aussi elle-même le séjour dans l'eau : si ta position est un moment très proche de l'axe du cours d'eau (pas dans un véhicule, pas en déplacement rapide), le temps dans l'eau démarre et tu reçois un avis. Si c'est faux, touche **Retour à terre**. Cela nécessite le réseau hydrographique (fonction « Zone de recherche »).

La direction d'intervention démarre et termine aussi le temps dans l'eau d'autres personnes (boutons **🌊 Dans l'eau** et **À terre** dans la fenêtre « Intervenants » et dans l'info personne) et saisit la température de l'eau dans la carte « Temps dans l'eau ». Sous **Protection thermique des intervenants**, elle y règle aussi la protection thermique de chaque personne avant qu'elle n'aille dans l'eau ; si le temps dans l'eau court déjà, la nouvelle valeur indicative s'applique immédiatement.

> S'il n'y a pas de station de l'OFEV avec température de l'eau près du PLS ou des intervenants, la température indique « inconnue ». L'app cherche alors à nouveau chaque minute dès que des positions sont transmises.

## Distance fluviale {#flussdistanz}

L'outil **Distance fluviale** mesure la distance entre deux points le long du cours d'eau. Vous le trouvez comme bouton de carte et dans le menu sous « Outils », aussi sans intervention.

1. Toucher le bouton de carte **Distance fluviale**.
2. Toucher le point de départ A sur la carte.
3. Toucher le point d'arrivée B.

L'app place les deux points sur le cours d'eau le plus proche et affiche la distance le long du cours d'eau et à vol d'oiseau, le sens du courant, le temps de dérive avec la vitesse du courant de l'intervention (sans intervention, vous la saisissez) et la durée pour quelques vitesses de comparaison. Si le trajet passe un barrage ou un autre danger, cela est également indiqué. Au maximum 50 km à vol d'oiseau.

## Navigation {#navigation}

Le bouton 🧭 des intervenants, victimes, PLS, marqueurs, sites fixes et stations de mesure transmet la destination à votre app de navigation (Google Maps, Plans d'Apple, Waze ou une autre app). Vous choisissez l'app dans votre [profil](#profil/anzeige). Si un site fixe a plusieurs points de navigation (p. ex. accès), vous en choisissez un.

Les marqueurs sans popup (intervenants, PLS) demandent brièvement une confirmation quand on les touche, pour que l'app de navigation ne s'ouvre pas par erreur.

## Stations de mesure {#messstationen}

Le bouton de carte **Stations de mesure** affiche niveau, débit et température de l'eau des stations de mesure de l'OFEV :

- **Au PLS** : les stations les plus proches, avec les valeurs au début de l'intervention et les actuelles.
- **Mes stations de mesure** : votre propre sélection, aussi sans intervention (dans le [profil](#profil/messstationen)).

![Stations de mesure](img/messstationen.jpg)

Les marqueurs sur la carte sont colorés selon le degré de danger. Les valeurs au début et à la fin d'une intervention figurent dans le journal d'intervention.

## Radio {#funk}

Si la radio est configurée, le bouton de carte **Radio** ouvre deux onglets :

- **Conférence en direct** : radio vocale en temps réel dans un canal. **Rejoindre** et **Quitter** existent aussi directement sur la carte en bas à gauche. Avec le bouton direct, vous parlez tant que vous le maintenez ; « Micro ouvert » laisse le micro ouvert.
- **Annonces** : messages vocaux. Maintenir le bouton, parler, relâcher. Les autres entendent l'annonce immédiatement ou plus tard ; elle figure avec ▶ dans le journal et, si configuré, aussi sous forme de texte.

Les canaux que vous entendez et où vous pouvez parler dépendent de votre rôle spécial et de la direction d'intervention. La direction d'intervention peut rendre des personnes muettes. Dans le profil, vous définissez si les annonces sont lues automatiquement (« Écoute »).

## Véhicules {#fahrzeuge}

Les véhicules équipés d'une balise Bluetooth apparaissent sur la carte dès que l'app native d'un participant les voit. La liste se trouve dans la fenêtre « Intervenants » sous « Véhicules » : qui est à bord, ce qui est chargé (bateau sur la remorque) et où il a été vu en dernier. Charger et décharger se fait via la popup du véhicule.

## Documents {#dokumente}

Le bouton de carte **📚 Documents** ouvre la documentation de votre organisation, aussi sans intervention. Tout en haut se trouve la recherche : elle trouve titres, descriptions, mots-clés, le texte des pages et le texte des PDF, avec numéro de page. En dessous figurent les sections que l'administration a configurées, p. ex. confirmations de lecture ouvertes, ★ favoris, documents sur des sites de la zone d'intervention, documents pour ce type d'intervention et les dossiers. Si c'est permis, vous disposez les sections pour vous avec **Disposer les sections**.

Chaque ligne affiche à gauche le type (PDF, TEXTE, LIEN …), à côté titre, type, taille et date ainsi que des pastilles comme « ⬇ hors ligne » ou le site (📍). À droite se trouvent **Ouvrir** et l'étoile des favoris. Pour les résultats de recherche, le dossier est affiché à la place, et en dessous l'extrait, p. ex. « p. 3 : « … rouleau … » » ou « Mot-clé : rouleau » ; les mots recherchés sont mis en évidence.

- **Voir** : l'app affiche directement pages, images et PDF ; les autres fichiers sont téléchargés ou ouverts dans une app adaptée. Les liens s'ouvrent dans le navigateur.
- **★** (ou ☆) épingle une entrée. Les favoris et les entrées désignées par la rédaction sont aussi enregistrés **hors ligne** sur l'appareil.
- **Confirmation de lecture** : si l'organisation exige que vous lisiez un document, il figure avec un délai dans le menu (carte « 📚 Documentation ») et avec un compteur sur le bouton de carte. En bas ou en haut du document, vous confirmez avec **Lu et compris**.
- **Proposer** : s'il manque quelque chose ou si quelque chose est faux, vous envoyez une proposition à la rédaction, sur une entrée ou dans un dossier. Dans le navigateur, vous pouvez aussi joindre un fichier.
- **Sites fixes** : si un document est rattaché à un site (p. ex. le plan d'intervention d'un barrage), il figure dans la popup du site (dans l'app dans la fiche d'info) sous « Documents pour ce site » : pour chaque document le type (PDF, TEXTE …), le titre, « hors ligne » s'il est enregistré sur l'appareil, et la date. Le toucher l'ouvre.

Dans l'onglet « Direction », sous **Documents pour les externes**, la direction d'intervention peut transmettre des documents à des externes sans app : sur la page invité de la radio ou via un lien propre. Le partage prend fin avec l'intervention.

## Journal d'intervention {#log}

L'onglet **Journal** montre le déroulement de l'intervention : arrivées, rôles spéciaux, trajets (« se rend de A à B »), messages en texte intégral, marqueurs, victimes, mesures et plus. Le **Mode agrandi** affiche le journal en plein écran, p. ex. sur un écran au poste de commandement.

![Onglet « Journal »](img/menu-log.jpg)

## Assistant IA {#ai}

Si votre organisation a configuré l'assistant IA, vous trouvez dans l'onglet « Intervention » **✨ Résumé de la situation** et **✨ Poser une question**. L'assistant fait seulement des propositions ; rien n'est repris automatiquement. Qu'il travaille sur demande ou en accompagnement, vous le définissez dans le [profil](#profil/ai).

## Outils {#werkzeuge}

Sous « Outils » dans le menu se trouvent la [distance fluviale](#einsatz/flussdistanz) et, selon vos droits, d'autres aides. Ils fonctionnent aussi sans intervention en cours.
