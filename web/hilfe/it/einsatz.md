# In intervento

## Il menu {#menu}

Il menu ☰ mostra in alto l'intervento: messaggio, ora dell'allarme, livello d'allarme, PLS, velocità della corrente e se l'intervento viene registrato o è visibile pubblicamente. Sotto ci sono le schede:

| Scheda | Contenuto |
|---|---|
| Intervento | Partecipazione, Il mio intervento, velocità della corrente, livello & portata, strumenti, assistente AI |
| Direzione | solo per la direzione dell'intervento, vedi [Direzione dell'intervento](#leitung) |
| Esercitazione | solo per la direzione dell'esercitazione, vedi [Esercitazioni](#planung/freigabe) |
| Registro | Registro dell'intervento in diretta |
| Info | i tuoi diritti in questo intervento, persona, server, versione |

![Scheda «Intervento»](img/menu-einsatz.jpg)
![Scheda «Info» con i propri diritti](img/menu-info.jpg)

All'apertura il menu è sempre su «Intervento» (per la direzione dell'esercitazione su «Esercitazione»).

## Soccorritori {#einsatzkraefte}

Il pulsante della mappa **Soccorritori** apre l'elenco di tutti i partecipanti, raggruppati per ruolo speciale, seguiti dai soccorritori esterni e dai veicoli. Per ogni persona vedi opzione d'intervento, età della posizione e coordinate.

![Elenco dei soccorritori](img/einsatzkraefte.jpg)

- **Centra** sposta la mappa sulla persona, **Segui** la tiene nell'immagine.
- **ℹ Info** mostra immagine del profilo, ruolo speciale, opzione d'intervento e gruppi d'intervento. La stessa finestra si apre toccando un soccorritore sulla mappa.
- 🧭 passa la posizione alla tua [app di navigazione](#einsatz/navigation).
- I gruppi si aprono e chiudono; il dispositivo se lo ricorda.

Qui la direzione dell'intervento può modificare i ruoli speciali, rimuovere persone dall'intervento e registrare [soccorritori esterni](#leitung/externe).

## Messaggi {#nachrichten}

Il pulsante della mappa **Messaggi** apre la cronologia. Tutti coloro che hanno accettato l'intervento vedono tutti i messaggi. I destinatari stabiliscono solo chi viene notificato; i messaggi indirizzati a te sono contrassegnati. Il numero rosso sul pulsante indica i non letti.

![Messaggi](img/nachrichten.jpg)

1. Inserisci il testo.
2. Facoltativamente scegli i destinatari (raggruppati per ruolo speciale). Senza selezione vengono notificati tutti.
3. Facoltativamente **Come marcatore sulla mappa**: alla tua posizione o in un punto che poi tocchi sulla mappa.
4. **Invia**.

I messaggi salvano la tua posizione, se la condividi. I marcatori dei messaggi compaiono come categoria propria sulla mappa; può rimuoverli chi li ha inviati o la direzione dell'intervento. Chi può inviare lo stabilisce l'amministrazione per ogni ruolo speciale.

## Vittime {#rettlinge}

Il pulsante della mappa **Vittime** mostra le persone cercate in tre gruppi: **Dispersa** (senza posizione), **Posizione nota** e **Salvata**. Il numero rosso conta quelle non ancora salvate.

![Vittime](img/rettlinge.jpg)

- **+ Registra vittima**: nome e descrizione. Senza nome si chiama «Vittima sconosciuta».
- **📍 Luogo di ritrovamento**: alla tua posizione o scelto sulla mappa. Vale l'ultimo luogo segnalato.
- **Salvata** o **Annulla**.
- **Area di ricerca** (per le disperse): inquadratura sulla sua sottoarea.
- **⋯** apre altri pulsanti: reimposta il luogo di ritrovamento, **Imposta PLS** (PLS proprio per questa vittima), modifica e, per la direzione dell'intervento, rimuovi.

Registrare, segnalare il luogo di ritrovamento e segnalare «salvata» possono tutti coloro che hanno accettato l'intervento.

## Marcatori {#markierungen}

Il pulsante della mappa **Marcatori** mostra tre gruppi:

- **Point last seen (PLS)**: il PLS dell'intervento e i PLS delle vittime. I PLS sbiaditi non contano più per l'area di ricerca (vittima trovata).
- **Situazione**: marcatori, aree e linee della direzione dell'intervento.
- **Luoghi fissi**: luoghi dell'organizzazione come punti di varo o magazzini; con un'area di ricerca suddivisi in «Nell'area d'intervento» e «Fuori».

![Marcatori](img/markierungen.jpg)

Con le spunte nascondi singole voci o interi gruppi sulla mappa; **Mostra** sposta lì la mappa. Nuovi marcatori, aree e linee li imposta la direzione dell'intervento (vedi [Disegnare la situazione](#leitung/lage)).

## Velocità della corrente {#fliessgeschwindigkeit}

Nella scheda «Intervento» tutti i partecipanti impostano la **velocità della corrente** in m/s. Da essa l'app calcola la lunghezza dell'area di ricerca. Le stazioni di misura al PLS (vedi sotto) aiutano nella stima.

## Distanza fluviale {#flussdistanz}

Lo strumento **Distanza fluviale** misura la distanza tra due punti lungo il corso d'acqua. Lo trovi come pulsante della mappa e nel menu sotto «Strumenti», anche senza intervento.

1. Tocca il pulsante della mappa **Distanza fluviale**.
2. Tocca il punto di partenza A sulla mappa.
3. Tocca il punto d'arrivo B.

L'app porta entrambi i punti sul corso d'acqua più vicino e mostra la distanza lungo il corso d'acqua e in linea d'aria, la direzione della corrente, il tempo di deriva con la velocità della corrente dell'intervento (senza intervento la inserisci tu) e la durata per alcune velocità di confronto. Se il percorso passa una traversa o un altro pericolo, viene indicato anche questo. Al massimo 50 km in linea d'aria.

## Navigazione {#navigation}

Il pulsante 🧭 di soccorritori, vittime, PLS, marcatori, luoghi fissi e stazioni di misura passa la destinazione alla tua app di navigazione (Google Maps, Mappe di Apple, Waze o un'altra app). Quale app, lo scegli nel [profilo](#profil/anzeige). Se un luogo fisso ha più punti di navigazione (per esempio accessi), ne scegli uno.

I marcatori senza popup (soccorritori, PLS) chiedono brevemente conferma quando li tocchi, perché l'app di navigazione non si apra per sbaglio.

## Stazioni di misura {#messstationen}

Il pulsante della mappa **Stazioni di misura** mostra livello, portata e temperatura dell'acqua delle stazioni di misura dell'UFAM:

- **Al PLS**: le stazioni più vicine, con i valori all'inizio dell'intervento e quelli attuali.
- **Le mie stazioni di misura**: la tua selezione, anche senza intervento (nel [profilo](#profil/messstationen)).

![Stazioni di misura](img/messstationen.jpg)

I marcatori sulla mappa sono colorati secondo il grado di pericolo. I valori all'inizio e alla fine di un intervento si trovano nel registro dell'intervento.

## Radio {#funk}

Se la radio è configurata, il pulsante della mappa **Radio** apre due schede:

- **Conferenza in diretta**: radio vocale in tempo reale in un canale. **Partecipa** ed **Esci** ci sono anche direttamente sulla mappa in basso a sinistra. Con il tasto in diretta parli finché lo tieni premuto; «Microfono aperto» lascia aperto il microfono.
- **Annunci**: messaggi vocali. Tieni premuto il tasto, parla, rilascia. Gli altri sentono l'annuncio subito o più tardi; compare con ▶ nel registro e, se configurato, anche come testo.

Quali canali senti e dove puoi parlare dipende dal tuo ruolo speciale e dalla direzione dell'intervento. La direzione dell'intervento può silenziare le persone. Nel profilo imposti se gli annunci vengono riprodotti automaticamente («Ascolto»).

## Veicoli {#fahrzeuge}

I veicoli con un beacon Bluetooth compaiono sulla mappa non appena l'app nativa di un partecipante li vede. L'elenco si trova nella finestra «Soccorritori» sotto «Veicoli»: chi è a bordo, cosa è caricato (barca sul rimorchio) e dove è stato visto l'ultima volta. Caricare e scaricare si fa dal popup del veicolo.

## Documenti {#dokumente}

Il pulsante della mappa **📚 Documenti** apre la documentazione della tua organizzazione, anche senza intervento. In cima c'è la ricerca: trova titoli, descrizioni, parole chiave, il testo delle pagine e il testo dei PDF, con il numero di pagina. Sotto ci sono le sezioni che l'amministrazione ha configurato, per esempio conferme di lettura aperte, ★ preferiti, documenti nei luoghi dell'area d'intervento, documenti per questo tipo d'intervento e le cartelle. Se è permesso, disponi le sezioni per te con **Disponi le sezioni**.

Ogni riga mostra a sinistra il tipo (PDF, TESTO, LINK …), accanto titolo, tipo, dimensione e data, e chip come «⬇ offline» o il luogo (📍). A destra ci sono **Apri** e la stella dei preferiti. Per i risultati della ricerca compare invece la cartella e sotto il passaggio trovato, per esempio «p. 3: «… rullo …»» o «Parola chiave: rullo»; le parole cercate sono evidenziate.

- **Visualizza**: l'app mostra direttamente pagine, immagini e PDF; gli altri file li scarichi o li apri in un'app adatta. I link si aprono nel browser.
- **★** (o ☆) fissa una voce. I preferiti e le voci scelte dalla redazione vengono salvati anche **offline** sul dispositivo.
- **Conferma di lettura**: se l'organizzazione richiede che tu legga un documento, compare con una scadenza nel menu (scheda «📚 Documentazione») e con un contatore sul pulsante della mappa. In fondo o in cima al documento confermi con **Letto e compreso**.
- **Proponi**: se manca qualcosa o qualcosa è sbagliato, invii una proposta alla redazione, su una voce o in una cartella. Nel browser puoi anche allegare un file.
- **Luoghi fissi**: se un documento è associato a un luogo (p. es. il piano d'intervento di una traversa), compare nel popup del luogo (nell'app nella scheda informativa) sotto «Documenti per questo luogo»: per ogni documento il tipo (PDF, TESTO …), il titolo, «offline» se è salvato sul dispositivo, e la data. Toccandolo si apre.

Nella scheda «Direzione», sotto **Documenti per esterni**, la direzione dell'intervento può condividere singoli documenti con esterni senza app: sulla pagina ospite della radio o con un link proprio. La condivisione termina con l'intervento.

## Registro dell'intervento {#log}

La scheda **Registro** mostra lo svolgimento dell'intervento: arrivi, ruoli speciali, tragitti («va da A a B»), messaggi per esteso, marcatori, vittime, misure e altro. La **Modalità grande** mostra il registro a schermo intero, per esempio su uno schermo nel posto di comando.

![Scheda «Registro»](img/menu-log.jpg)

## Assistente AI {#ai}

Se la tua organizzazione ha configurato l'assistente AI, nella scheda «Intervento» trovi **✨ Riepilogo della situazione** e **✨ Fai una domanda**. L'assistente fa solo proposte; non viene ripreso nulla automaticamente. Se lavora su richiesta o in accompagnamento, lo imposti nel [profilo](#profil/ai).

## Strumenti {#werkzeuge}

Sotto «Strumenti» nel menu trovi la [distanza fluviale](#einsatz/flussdistanz) e, secondo i tuoi diritti, altri ausili. Funzionano anche senza un intervento in corso.
