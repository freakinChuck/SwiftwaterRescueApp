# Alarme et participation

## L'alarme {#alarm}

Quand quelqu'un démarre une intervention ou un exercice, l'app alarme toutes les personnes mobilisées. L'intensité de l'alarme dépend du type d'intervention (niveau d'alarme) :

| Niveau d'alarme | Ce qui se passe |
|---|---|
| Critique | Avis rouge avec triple son, le push reste affiché. Dans l'app native plein écran et son continu, même avec « Ne pas déranger ». |
| Élevé | Avis rouge avec son, le push reste affiché. Dans l'app native plein écran. |
| Normal | Avis rouge avec un son bref. Dans l'app native plein écran. |
| Bas | Avis rouge sans son, notification push silencieuse. Dans l'app native plein écran. |
| Aucun | Avis rouge seulement dans l'app ouverte, sans son ni push. Dans l'app native plein écran. |

Si l'app est ouverte, l'avis apparaît en haut à droite jusqu'à ce que vous le touchiez ; cela vaut pour tous les niveaux d'alarme, aussi pour les interventions secondaires. Avec l'app fermée, il arrive comme notification push. Le toucher ouvre l'app et montre la zone d'intervention.

L'avis d'alarme (plein écran dans l'app native) contient déjà les options d'engagement : avec **Accepter : …**, vous acceptez directement l'intervention ; la carte montre ensuite la zone d'intervention. **Seulement acquitter** (dans l'app web : toucher à côté des boutons) ferme l'alarme sans accepter ; vous pouvez ensuite accepter dans le menu comme décrit ci-dessous.

Avec le **mode exercice** dans le [profil](#profil/alarmierung), vous êtes aussi alarmé de manière critique pour les exercices, si le type d'intervention le prévoit. Si vous êtes [annoncé absent](#profil/abwesenheit), vous n'êtes pas alarmé, mais vous voyez l'intervention et pouvez participer volontairement.

## Accepter une intervention {#annehmen}

Le plus rapide est directement dans l'alarme (voir [L'alarme](#alarm)). Tant que vous ne participez pas, la carte affiche sous la pastille d'intervention la barre jaune **Intervention pas encore acceptée**. La toucher ouvre le menu à la carte « Participation ».

![Avis « Intervention pas encore acceptée »](img/hinweis-annehmen.jpg)
![Participation avec les options d'engagement](img/teilnahme.jpg)

Choisissez-y comment vous vous engagez, p. ex. « Trajet via la base » ou « Trajet direct ». Vous participez alors à l'intervention : la direction d'intervention vous voit dans la liste, vous voyez tous les messages et pouvez collaborer. Après l'acceptation, la carte montre la zone de recherche.

Vous n'avez pas besoin de refuser une intervention : qui n'accepte pas ne participe pas.

## Options d'engagement {#einrueckoption}

Les options d'engagement sont définies par l'administration. Elles déterminent :

- si l'app **partage votre position** (les options « sans position » ne partagent rien),
- si vous recevez les **droits de la direction d'intervention** (p. ex. « Coordinateur PC arrière » ; alors sans rôle spécial),
- si vous comptez dans le compteur des intervenants.

## Mon intervention {#mein-einsatz}

Après l'acceptation, le menu affiche dans l'onglet « Intervention » la carte **Mon intervention** : option d'engagement, état du partage de position et votre **rôle spécial** (p. ex. sauveteur, pilote de bateau, poste de sécurité). Vous choisissez le rôle spécial vous-même ; la direction d'intervention peut le modifier.

![Onglet « Intervention » dans le menu](img/menu-einsatz.jpg)

## Partager la position {#standort}

Avec les options d'engagement avec position, l'app partage votre position tant que vous participez et que l'app est ouverte. Le navigateur demande l'autorisation la première fois. La direction d'intervention vous voit alors sur la carte, et les trajets apparaissent dans le journal d'intervention.

- Sur téléphone, l'app web ne partage la position que tant qu'elle est au premier plan. Pour partager écran verrouillé, il faut l'[app native](#app/standort).
- **Définir la position manuellement (test)** place votre position sur un point de la carte, p. ex. pour des exercices au bureau. « Utiliser la position GPS » revient en arrière.
- Les positions sur l'eau (bateau) ne comptent pas comme trajet.

## Quitter l'intervention {#verlassen}

**Quitter l'intervention** dans la carte « Mon intervention » met fin à votre participation, p. ex. lorsque vous êtes relevé. Vous pouvez revenir plus tard. Si la direction d'intervention vous a retiré de l'intervention, vous recevez un avis ; un retour est aussi possible dans ce cas.

## Plusieurs interventions à la fois {#parallel}

Si plusieurs interventions sont en cours, le menu affiche au-dessus des onglets la carte **Interventions en cours**. Vous y changez l'intervention affichée. Vous ne participez toujours qu'à une seule : si vous en acceptez une autre, vous quittez la précédente. Les autres interventions apparaissent pâles sur la carte ; dans la fenêtre [Affichage](#karte/ansicht), vous les affichez et masquez sous « Autres interventions ».

## Fin de l'intervention {#ende}

Quand la direction d'intervention termine l'intervention, vous recevez un avis et les notifications de l'intervention disparaissent. Journal et replay se trouvent ensuite sous [Mes interventions](#auswertung/meine).
