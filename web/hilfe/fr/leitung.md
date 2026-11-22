# Direction d'intervention

## Qui dirige ? {#rechte}

Les droits de la direction d'intervention reviennent à tous ceux qui ont accepté l'intervention et

- portent le rôle spécial **Direction d'intervention**,
- participent avec une option d'engagement avec droits de direction (p. ex. « Coordinateur PC arrière »),
- ont le droit « Diriger toute intervention » ou
- ont démarré l'intervention.

Sans participation, il n'y a pas de droits de direction d'intervention (exception : la direction d'exercice). L'onglet **Info** du menu montre les droits que vous avez actuellement. La direction d'intervention voit en plus l'onglet **Direction** dans le menu.

![Onglet « Direction »](img/menu-leitung.jpg)

## Démarrer une intervention {#starten}

Sans intervention en cours, le menu affiche un bouton par type d'intervention que vous pouvez démarrer, p. ex. **Démarrer une intervention** ou **Démarrer un exercice**.

![Menu sans intervention](img/start.jpg)
![Formulaire « Démarrer une intervention »](img/start-formular.jpg)

Dans le formulaire :

- **Message** (obligatoire) : ce qui s'est passé. Il apparaît dans l'alarme.
- **PLS** (facultatif) : saisir des coordonnées ou choisir sur la carte. Sans PLS, il n'y a de zone de recherche que lorsqu'une victime a un PLS.
- **Réception de l'alarme** : vide = maintenant ; sinon l'heure effective (au maximum 7 jours en arrière).
- **Mobilisation** : groupes d'intervention, pour certains types aussi des personnes individuelles, ou toutes les personnes mobilisables.
- **Enregistrer** (pour le replay) et **Hélicoptères sur la carte**, présélectionnés par type d'intervention.

Pour une intervention de suivi (intervention secondaire), vous choisissez l'intervention à laquelle elle se rapporte et reprenez au besoin le PLS, la vitesse du courant et les victimes disparues.

Si une intervention est déjà en cours, vous trouvez dans l'onglet « Intervention » la carte **Démarrer une autre intervention**.

## Définir le PLS {#pls}

Dans l'onglet « Direction », vous définissez, déplacez ou retirez le **PLS de l'intervention** avec son heure (début de la dérive). Il vaut pour toutes les victimes sans PLS propre. Tous les participants définissent un PLS propre par victime dans la fenêtre [Victimes](#einsatz/rettlinge).

## Mobilisation et mobilisation complémentaire {#aufgebot}

La carte **Mobilisation** montre qui est mobilisé. **Mobiliser davantage** alarme d'autres groupes d'intervention, des personnes individuelles ou tout le monde ; seuls les nouveaux mobilisés sont alarmés. **Autoriser tout le monde à rejoindre** permet aussi aux non-mobilisés de participer, sans les alarmer.

Si votre organisation a une **convention** avec une organisation partenaire (Administration → Connectivité → [Organisations partenaires](#verwaltung/partner)) et que vous pouvez mobiliser tous les groupes d'intervention, la mobilisation au démarrage et la mobilisation complémentaire sont divisées en deux parties : **Propre organisation** et **Organisations partenaires** avec les groupes d'intervention que le partenaire vous a libérés. Chaque organisation a son propre serveur avec sa propre adresse ; la convention vaut par type d'intervention, tel que le partenaire l'a attribué. Si vous ne choisissez que des groupes de partenaires, personne n'est alarmé dans votre propre organisation. Le partenaire reçoit sur son serveur sa propre **intervention liée** avec ses propres réglages, rôles et options d'engagement ; ses gens y sont alarmés et participent dans leur app. Le menu affiche la carte **Intervention commune** avec les organisations impliquées, le canal commun et, si configurée partout, la conférence commune. La situation des partenaires (intervenants, marqueurs, sites fixes, véhicules) apparaît pâle sur la carte et peut être masquée par organisation dans la fenêtre « Affichage » sous « Autres interventions ». Dans les fenêtres « Intervenants » et « Marqueurs », chaque organisation partenaire a son propre groupe avec ses intervenants (aussi sans position) et externes, respectivement ses PLS, marqueurs, zones, lignes et sites fixes ; vous pouvez les y centrer et vous y rendre, mais pas les modifier. Vous pouvez **suivre** les intervenants des partenaires comme les vôtres, ouvrir leur **Info** (aussi en les touchant sur la carte) et, si le partenaire a libéré l'appel direct pour tous, les **appeler**. **Les victimes sont communes :** qui saisit, modifie, définit le lieu de découverte, annonce sauvée ou retire une victime dans l'une des organisations la modifie pour tous ; le journal d'intervention indique la personne avec son organisation. Quand l'organisation déclenchante termine l'intervention, les interventions liées se terminent aussi. Si un partenaire n'est pas joignable, c'est indiqué chez lui ; son intervention continue sur son serveur.

## Intervenants externes {#externe}

Les intervenants externes n'ont pas de compte dans l'app, p. ex. des postes de sécurité des pompiers. Dans la fenêtre « Intervenants », vous les saisissez avec **+ Intervenant externe** :

1. Choisir un modèle (il apporte nom, rôle spécial, abréviation et couleur) ou « Nouveau sans modèle ».
2. Toucher la position sur la carte. Les modèles avec position sont placés directement.

Les externes apparaissent carrés et en pointillé sur la carte. Avec le rôle spécial d'un poste, ils limitent la zone de recherche comme une personne. Modifier (✎) et retirer (✕) dans la liste.

## Dessiner la situation {#lage}

Dans la fenêtre « Marqueurs », la direction d'intervention place :

- **+ Marqueur** : un point avec désignation, couleur et éventuellement un symbole tactique.
- **+ Zone** : une surface. Toucher les sommets, puis « Terminé » (Annuler la dernière action et Abandonner dans la barre en haut).
- **+ Ligne** : p. ex. une ligne de barrage ou un axe de sauvetage, points dans l'ordre du dessin.

Tout le monde voit la situation immédiatement. Retirer via ✕ dans la liste. Un objet de la couche de géodonnées (barrage, pont) peut être repris directement comme marqueur via sa popup.

## Gérer les intervenants {#einsatzkraefte}

Dans la fenêtre « Intervenants », la direction d'intervention modifie le **rôle spécial** d'une personne et **retire** des personnes de l'intervention (un retour reste possible). L'onglet « Direction » a en plus la carte « Intervenants » à cet effet.

## Enregistrement {#aufzeichnung}

La carte **Enregistrement** démarre, met en pause, reprend et termine l'enregistrement des positions pour le [replay](#auswertung/replay). Tout le monde voit dans le menu si l'enregistrement est actif (« ● enregistrement en cours »). Sans enregistrement, il y a un journal mais pas de replay.

## Vue publique {#oeffentlich}

La carte **Vue publique (sans connexion)** crée un lien par lequel d'autres peuvent regarder sans compte, p. ex. la police ou une organisation partenaire. Ils voient la carte, le PLS, la zone de recherche, les intervenants, les marqueurs et les victimes, sur demande aussi le journal.

- **Anonyme** (par défaut) : le rôle spécial au lieu des noms, les victimes comme « Victime N » sans description.
- **Désactiver** invalide le lien immédiatement ; à la prochaine activation, il y en a un nouveau.
- Après la fin de l'intervention, le lien n'affiche plus que « terminée ».

## Barrières fluviales {#flusssperren}

Les sites fixes avec la propriété « limite la zone de recherche » (p. ex. un barrage ou un piège à flottants) arrêtent la zone de recherche. Dans la carte **Barrières fluviales**, vous les désactivez pour cette intervention en totalité ou individuellement, p. ex. si un barrage est ouvert. Les sites avec un avertissement (danger de mort, rouleau) sont mis en évidence dans la zone de recherche.

## Hélicoptères {#helikopter}

Si l'affichage est configuré, la carte montre les hélicoptères et autres aéronefs à partir de données ADS-B ouvertes : rouge = hélicoptères de sauvetage (p. ex. Rega), jaune = autres hélicoptères. Dans la carte **Hélicoptères**, vous activez et désactivez l'affichage pour l'intervention. Les hélicoptères volant bas dans les vallées manquent souvent, car aucun récepteur ne les voit.

## Radio pour les externes {#funk-extern}

Avec la carte **Radio pour les externes (lien)**, vous créez un lien par lequel une personne externe sans compte écoute un canal radio et, si c'est permis, parle. Dans **Radio & conférence**, vous choisissez les canaux de l'intervention.

## Terminer l'intervention {#beenden}

**Terminer l'intervention** dans l'onglet « Direction » clôt l'intervention pour tous. Les mesures des stations au PLS vont dans le journal, un enregistrement en cours se termine et, si configuré, les participants reçoivent une demande de [feedback d'intervention](#auswertung/feedback).
