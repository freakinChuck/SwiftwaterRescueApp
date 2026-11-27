# La carte

Par défaut, la carte affiche l'image aérienne de swisstopo. Déplacer et zoomer comme d'habitude : glisser, deux doigts ou molette.

## Boutons de carte {#knoepfe}

Les boutons de carte se trouvent en bas à droite. Les chiffres rouges indiquent ce qui est non lu ou ouvert (victimes non sauvées, messages non lus), le chiffre neutre sur « Intervenants » le nombre de participants.

| Bouton | Ouvre |
|---|---|
| Cadrage | Choix de ce que la carte doit montrer (voir ci-dessous) |
| Intervenants | Liste de tous les intervenants, groupés par rôle spécial ([plus](#einsatz/einsatzkraefte)) |
| Victimes | Personnes disparues et retrouvées ([plus](#einsatz/rettlinge)) |
| Messages | Messages de l'intervention ([plus](#einsatz/nachrichten)) |
| Radio | Annonces et conférence en direct ([plus](#einsatz/funk)) |
| Marqueurs | PLS, marqueurs, zones, lignes et sites fixes ([plus](#einsatz/markierungen)) |
| Stations de mesure | Niveau et débit ([plus](#einsatz/messstationen)) |
| Distance fluviale | Distance entre deux points le long du cours d'eau ([plus](#einsatz/flussdistanz)) |

Le bouton **»** au-dessus des boutons de carte les replie, pour voir davantage de carte sur téléphone. Replié, il affiche la somme des chiffres rouges. Sur téléphone, les fenêtres latérales couvrent les boutons de carte ; elles se ferment via ✕.

## Affichage {#ansicht}

Le bouton en haut à droite (symbole des couches) ouvre la fenêtre **Affichage** :

- **Carte de base** : images aériennes et cartes que votre organisation a activées. Le choix vaut jusqu'à la fin de l'intervention.
- **Sur la carte** : une coche par catégorie, p. ex. intervenants, externes, victimes, PLS, zone de recherche, marqueurs, zones, lignes, hélicoptères, stations de mesure, véhicules et la couche de géodonnées. « Tout afficher » et « Tout masquer » agissent sur toutes à la fois.
- **Sites fixes** avec leurs catégories et « Noms sur la carte ».

![Fenêtre « Affichage »](img/ansicht.jpg)

La sélection ne vaut que pour la carte sur cet appareil ; les listes des fenêtres latérales restent complètes. Les postes de sécurité masqués limitent toujours la zone de recherche.

## Cadrage {#kamerafokus}

**Cadrage** oriente la carte :

- **Zone de recherche** : toute la zone de recherche, avec plusieurs PLS aussi par sous-zone.
- **Intervenants** : tous les intervenants avec position.
- **Vue d'ensemble auto** : à chaque actualisation, la carte garde toutes les positions visibles dans l'image. Elle prend fin dès que vous déplacez ou zoomez la carte manuellement.

![Cadrage](img/kamerafokus.jpg)

Dans la liste des intervenants, vous pouvez **suivre** une personne : la carte reste alors sur elle. Le suivi prend fin via ✕ dans l'avis en haut, en déplaçant la carte ou si la personne quitte l'intervention.

## Tourner la carte {#drehen}

Sur téléphone, vous tournez la carte avec deux doigts, sur ordinateur avec Maj + molette ou en faisant glisser la boussole au-dessus des boutons de zoom. L'aiguille indique toujours le nord ; un clic sur la boussole réoriente la carte au nord.

## Propre position {#eigener-standort}

Le point bleu avec le cercle de précision est votre propre position, même sans intervention. Il sert seulement à l'affichage. Votre position n'est envoyée que si vous [participez](#alarm/standort) à une intervention. Si vous participez avec une option d'engagement sans position, le point est masqué.

Sans intervention, la carte montre à l'ouverture votre position et les sites fixes de votre organisation.

## Zone de recherche {#suchgebiet}

La zone de recherche suit le cours d'eau à partir du **PLS** (point last seen, dernier lieu connu de la personne disparue). Sa longueur résulte de la vitesse du courant et du temps écoulé depuis le PLS. Elle comprend le cours d'eau et une bande riveraine de 30 m de chaque côté et suit tous les bras aux bifurcations.

- Un **poste de sécurité ou d'observation** sur le cours d'eau arrête la zone de recherche sur son bras, de même que les **barrières fluviales** comme les barrages (voir [Barrières fluviales](#leitung/flusssperren)).
- Si chaque victime a son propre PLS, la zone de recherche est la réunion des sous-zones.
- L'avis en bas à gauche résume la zone de recherche ; un clic déplie les informations par PLS.
- Si le réseau hydrographique n'est pas disponible, l'app dessine un cercle autour du PLS.
- **Arrivée de la zone de recherche (ETA)** : pour les intervenants, les intervenants externes (p. ex. postes), les marqueurs et les emplacements fixes en aval de la zone de recherche, les fenêtres « Intervenants » et « Marqueurs » ainsi que l'info personne indiquent quand la zone de recherche atteindra l'endroit, avec l'heure et en relatif (p. ex. « Zone de recherche ici vers 14:35 (dans 23 min) »). Le calcul se fait le long du cours d'eau avec la vitesse du courant depuis le PLS ; l'endroit doit se trouver à 150 m au plus du cours d'eau. Si un poste ou un barrage se trouve entre les deux, ou si l'endroit est déjà dans la zone de recherche ou hors du réseau hydrographique chargé, l'indication manque.

Tous les participants définissent la vitesse du courant dans le menu (carte « Vitesse du courant & zone de recherche »).
