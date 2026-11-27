# La mappa

Di default la mappa mostra l'immagine aerea di swisstopo. Sposta e ingrandisci come al solito: trascinare, due dita o rotellina del mouse.

## Pulsanti della mappa {#knoepfe}

In basso a destra ci sono i pulsanti della mappa. I numeri rossi indicano ciò che è non letto o aperto (vittime non salvate, messaggi non letti), il numero neutro su «Soccorritori» il numero di partecipanti.

| Pulsante | Apre |
|---|---|
| Inquadratura | Scelta di cosa deve mostrare la mappa (vedi sotto) |
| Soccorritori | Elenco di tutti i soccorritori, raggruppati per ruolo speciale ([altro](#einsatz/einsatzkraefte)) |
| Vittime | Persone disperse e ritrovate ([altro](#einsatz/rettlinge)) |
| Messaggi | Messaggi dell'intervento ([altro](#einsatz/nachrichten)) |
| Radio | Annunci e conferenza in diretta ([altro](#einsatz/funk)) |
| Marcatori | PLS, marcatori, aree, linee e luoghi fissi ([altro](#einsatz/markierungen)) |
| Stazioni di misura | Livello e portata ([altro](#einsatz/messstationen)) |
| Distanza fluviale | Distanza tra due punti lungo il corso d'acqua ([altro](#einsatz/flussdistanz)) |

Il pulsante **»** sopra i pulsanti della mappa li ripiega, così sul telefono si vede più mappa. Ripiegato mostra la somma dei numeri rossi. Sul telefono le finestre laterali coprono i pulsanti della mappa; si chiudono con ✕.

## Vista {#ansicht}

Il pulsante in alto a destra (simbolo dei livelli) apre la finestra **Vista**:

- **Mappa di base**: immagini aeree e mappe che la tua organizzazione ha attivato. La scelta vale fino alla fine dell'intervento.
- **Sulla mappa**: una spunta per categoria, per esempio soccorritori, esterni, vittime, PLS, area di ricerca, marcatori, aree, linee, elicotteri, stazioni di misura, veicoli e il livello di geodati. «Mostra tutto» e «Nascondi tutto» agiscono su tutte insieme.
- **Luoghi fissi** con le loro categorie e «Nomi sulla mappa».

![Finestra «Vista»](img/ansicht.jpg)

La selezione vale solo per la mappa su questo dispositivo; gli elenchi nelle finestre laterali restano completi. I posti di sicurezza nascosti limitano comunque l'area di ricerca.

## Inquadratura {#kamerafokus}

**Inquadratura** orienta la mappa:

- **Area di ricerca**: tutta l'area di ricerca, con più PLS anche per ogni sottoarea.
- **Soccorritori**: tutti i soccorritori con posizione.
- **Panoramica automatica**: a ogni aggiornamento la mappa tiene nell'immagine tutte le posizioni visibili. Termina non appena sposti o ingrandisci la mappa a mano.

![Inquadratura](img/kamerafokus.jpg)

Nell'elenco dei soccorritori puoi **seguire** una persona: la mappa resta allora su di lei. Il seguire termina con ✕ nell'avviso in alto, spostando la mappa o quando la persona lascia l'intervento.

## Ruotare la mappa {#drehen}

Sul telefono ruoti la mappa con due dita, sul computer con Maiusc + rotellina o trascinando la bussola sopra i pulsanti dello zoom. L'ago punta sempre a nord; un clic sulla bussola riorienta la mappa a nord.

## Posizione propria {#eigener-standort}

Il punto blu con il cerchio di precisione è la tua posizione, anche senza intervento. Serve solo alla visualizzazione. La tua posizione viene inviata solo quando [partecipi](#alarm/standort) a un intervento. Se partecipi con un'opzione d'intervento senza posizione, il punto è nascosto.

Senza intervento la mappa mostra all'apertura la tua posizione e i luoghi fissi della tua organizzazione.

## Area di ricerca {#suchgebiet}

L'area di ricerca segue il corso d'acqua a partire dal **PLS** (point last seen, ultimo luogo noto della persona dispersa). La sua lunghezza risulta dalla velocità della corrente e dal tempo trascorso dal PLS. Comprende il corso d'acqua e una fascia di riva di 30 m per lato e alle diramazioni segue tutti i rami.

- Un **posto di sicurezza o d'osservazione** sul corso d'acqua termina l'area di ricerca sul suo ramo, così come gli **sbarramenti fluviali** quali le dighe (vedi [Sbarramenti fluviali](#leitung/flusssperren)).
- Se ogni vittima ha un proprio PLS, l'area di ricerca è l'unione delle sottoaree.
- L'avviso in basso a sinistra riassume l'area di ricerca; un clic apre i dati per ogni PLS.
- Se la rete idrografica non è disponibile, l'app disegna un cerchio attorno al PLS.
- **Arrivo dell'area di ricerca (ETA)**: per i soccorritori, i soccorritori esterni (p. es. posti), i marcatori e le ubicazioni fisse a valle dell'area di ricerca, le finestre «Soccorritori» e «Marcatori» e l'info persona indicano quando l'area di ricerca raggiungerà il punto, con l'ora e in relativo (p. es. «Area di ricerca qui verso le 14:35 (tra 23 min)»). Il calcolo avviene lungo il corso d'acqua con la velocità della corrente dal PLS; il punto deve trovarsi al massimo a 150 m dal corso d'acqua. Se in mezzo c'è un posto o uno sbarramento, oppure se il punto è già nell'area di ricerca o fuori dalla rete idrografica caricata, l'indicazione manca.

La velocità della corrente la impostano tutti i partecipanti nel menu (scheda «Velocità della corrente & area di ricerca»).
