# Primi passi

Swiftwater Rescue supporta i gruppi d'intervento del soccorso acquatico nell'allarme, nel coordinamento e nella panoramica della situazione quando una persona è dispersa in un corso d'acqua, e durante le esercitazioni. L'app funziona nel browser su telefono e computer. Per Android e iOS esiste inoltre un'app propria (vedi [App nativa](#app)).

Queste istruzioni descrivono tutte le funzioni. Quali vedi davvero dipende dai tuoi diritti e dalle impostazioni della tua organizzazione: le funzioni disattivate mancano del tutto nell'app.

> Ovunque nell'app trovi il pulsante **?**. Apre le istruzioni direttamente alla sezione corrispondente.

## Accedere {#anmelden}

Il tuo account lo ricevi dall'amministrazione della tua organizzazione: nome utente e una prima password. Al primo accesso devi impostare una password tua. Poi resti connesso sul dispositivo finché non esci.

![Accesso](img/anmeldung.jpg)

Sotto il pulsante «Accedi» trovi:

- **Prova la demo**: conoscere l'app senza account (vedi sotto),
- **Istruzioni per l'uso**: questa pagina,
- **Note legali, licenze, protezione dei dati** dell'organizzazione,
- la scelta della **lingua** («Automatico» usa la lingua del dispositivo).

Password dimenticata? L'amministrazione la reimposta. Ricevi di nuovo una prima password e poi ne imposti una tua.

## Modalità demo {#demo}

In modalità demo provi l'app d'intervento senza rischi. Tutto avviene solo in questa scheda del browser: nulla viene salvato sul server, nessuno viene allarmato e i tuoi interventi reali restano intatti. Se chiudi la scheda, lo stato della demo sparisce.

![Benvenuto della modalità demo](img/demo-start.jpg)

- **Con dati di esempio**: è già in corso un intervento sulla Reuss a Lucerna. Soccorritori simulati accettano l'intervento, arrivano, inviano messaggi e trovano la vittima.
- **Senza dati**: app vuota, avvii tu un intervento o un'esercitazione.
- Con la spunta **Diritti della direzione dell'intervento** dopo l'accettazione vedi tutte le funzioni della direzione dell'intervento.

Il segno viola **DEMO** in alto a sinistra riapre questo dialogo in qualsiasi momento, p. es. per ricominciare o terminare la demo. Le immagini di queste istruzioni provengono dalla modalità demo.

## Installare l'app {#installieren}

La web app si può mettere sulla schermata iniziale come un'app. Si apre poi senza barra degli indirizzi e può ricevere notifiche push.

- **Android (Chrome)**: menu ⋮ → «Installa app» o «Aggiungi a schermata Home». Nel profilo c'è anche il pulsante «Installa app».
- **iPhone e iPad (Safari)**: simbolo Condividi → «Aggiungi alla schermata Home». Su iPhone il push funziona solo così (da iOS 16.4).
- **Computer**: in Chrome ed Edge compare nella barra degli indirizzi un simbolo per installare.

Per allarmi affidabili anche con il telefono silenzioso consigliamo l'[app nativa](#app).

## Notifiche push {#push}

Affinché un allarme ti raggiunga anche ad app chiusa servono le notifiche push. Finché il push è disattivato sul tuo dispositivo, in cima al menu c'è un avviso con **Attiva push**. Puoi attivare e disattivare il push anche nel [profilo](#profil/alarmierung). «Più tardi» nasconde l'avviso per sette giorni.

> ! La web app non può aggirare «Non disturbare» né la modalità silenziosa. Per allarmi critici che suonano anche di notte usa l'[app nativa](#app/kritisch).

## L'interfaccia {#oberflaeche}

L'app d'intervento mostra la mappa a schermo intero. Tutti i comandi stanno sopra:

- **☰ Menu** in alto a sinistra: intervento, partecipazione, direzione, registro e strumenti (vedi [Il menu](#einsatz/menu)).
- **Etichetta dell'intervento** in alto al centro: l'intervento in corso. Toccandola si apre il menu.
- **Vista** in alto a destra (simbolo dei livelli): mappa di base e cosa compare sulla mappa (vedi [Vista](#karte/ansicht)).
- **Pulsanti della mappa** in basso a destra: inquadratura, soccorritori, vittime, messaggi, marcatori, stazioni di misura, radio e distanza fluviale. Ciascuno apre una finestra laterale.
- **Avviso sull'area di ricerca** in basso a sinistra.

![Mappa durante l'intervento con i pulsanti della mappa](img/karte.jpg)

Nell'intestazione del menu trovi:

| Pulsante | Significato |
|---|---|
| 👤 | Profilo: le tue impostazioni personali |
| 📋 | I miei interventi: registro e replay dei tuoi interventi terminati |
| ⚙ | Amministrazione (solo con i relativi diritti) |
| ? | Queste istruzioni |
| ✕ | Chiudi menu |

## Senza connessione {#offline}

Dopo ogni aggiornamento l'app memorizza l'ultimo stato. Se la connessione cade, mostra in alto un avviso con l'ora dell'ultima connessione e continua a lavorare con quello stato. Azioni come l'invio di messaggi non riescono allora con «Nessuna connessione al server». Non appena la connessione torna, l'app ricarica tutto. Le porzioni di mappa già viste restano visibili offline.

Se è stata installata una nuova versione, in basso compare «Nuova versione disponibile» con il pulsante **Ricarica**.
