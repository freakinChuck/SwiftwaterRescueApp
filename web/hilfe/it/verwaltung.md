# Amministrazione

Apri l'amministrazione con ⚙ nell'intestazione del menu. Funziona meglio al computer. A sinistra ci sono le sezioni per categoria (sul telefono come elenco di selezione); vedi solo le sezioni per cui hai un diritto. Da nove sezioni in su aiuta il campo di ricerca «Cerca sezione». Il pulsante **?** in alto apre le istruzioni della sezione scelta.

![Amministrazione, sezione «Utenti»](img/admin-benutzer.jpg)

In alto a destra: **All'app d'intervento**, **I miei interventi** e il menu dell'account (cambia password, esci, note legali, versione).

Tutte le sezioni hanno la stessa struttura:

- Le voci compaiono come schede una sotto l'altra, i pulsanti sempre nello stesso ordine: azioni proprie, **Modifica**, ↑/↓ per l'ordine e per ultimo **Rimuovi** o **Disattiva**.
- In alto c'è **+ Nuovo …**, per elenchi più lunghi con una ricerca accanto.
- Le voci disattivate sono chiuse sotto **Disattivato (N)**.
- Nelle impostazioni **Salva** salva l'intera scheda. Dopo una modifica compare accanto «Modifiche non salvate»; se cambi sezione, l'amministrazione chiede conferma. Ciò che è indicato con «salvato subito» (p. es. le funzioni per tipo d'intervento) vale senza salvare.

> I diritti valgono sempre anche sul server. Ciò che l'amministrazione nasconde non si può modificare nemmeno in altro modo.

## Persone {#personen}

### Utenti {#benutzer}

Creare persone, modificare nome e ruoli utente, reimpostare la password e disattivare. Con un nuovo account o una password reimpostata la persona deve impostare una propria password al prossimo accesso.

- **Disattiva** disconnette la persona e la toglie da un intervento in corso. I suoi dati restano, può essere riattivata.
- **Elimina** esiste solo per gli account che non hanno mai effettuato l'accesso e non compaiono in nessun intervento.
- **Elimina definitivamente** esiste per tutti gli altri account con il diritto speciale «Elimina definitivamente» (vedi [Elimina definitivamente](#endgueltig)).
- Puoi modificare solo persone di cui possiedi tu stesso tutti i diritti.

### Ruoli utente {#benutzerrollen}

I ruoli raggruppano diritti, per esempio «Soccorritore», «Responsabile esercitazioni» o «Amministratore di sistema». Una persona può avere più ruoli e riceve tutti i loro diritti. Il ruolo Amministratore di sistema ha sempre tutti i diritti e non si può modificare. Per ogni ruolo stabilisci anche quali gruppi d'intervento può convocare.

![Ruoli utente](img/admin-benutzerrollen.jpg)

### Gruppi d'intervento {#einsatzgruppen}

Gruppi come «Gruppo barca» o «Gruppo riva» con colore e membri. All'avvio di un intervento si convocano gruppi. Per ogni tipo d'intervento l'amministrazione stabilisce quali gruppi sono convocabili e preselezionati. «Mostra il colore accanto al nome» mostra il gruppo come punto accanto ai nomi nell'amministrazione.

![Gruppi d'intervento](img/admin-einsatzgruppen.jpg)

### Assenze {#abwesenheiten}

Assenze in corso e pianificate di tutte le persone (diritto «Gestire le assenze»). Gli assenti non vengono allarmati.

Sotto compaiono le persone che secondo i loro [orari di disponibilità regolari](#profil/einsatzzeiten) al momento non sono disponibili, e tutte le regole. **+ Regola** registra una regola per una persona, **Modifica** e **Rimuovi** funzionano come nel profilo. Tutto insieme nel calendario: **📅 Calendario** nell'intestazione ([Calendario](#profil/kalender)).

### Formazione {#ausbildung}

Catalogo delle competenze (per esempio «Bagnino di salvataggio», «Conduttore di barca») con validità, preavviso, obbligo per gruppo d'intervento e se la persona registra lei stessa il completamento. La panoramica mostra tutte le persone con lo stato per competenza, filtrabile per gruppo d'intervento e «solo con necessità d'intervento», con esportazione CSV.

## Intervento {#einsatz}

### Tipi d'intervento {#einsatztypen}

I tipi di intervento, per esempio «Intervento», «Esercitazione» o «Prova di picchetto». Per ogni tipo attivo c'è nell'app d'intervento un proprio pulsante di avvio. Per tipo:

- nome, colore, categoria (primario, secondario, evento libero),
- **livello d'allarme** (da critico a nessuno), livello per messaggi e fine dell'intervento, suono per l'allarme critico,
- gruppi d'intervento convocabili e preselezionati, singole persone ammesse, visibile ai non convocati,
- esercitazioni preparate ammesse, preselezione «Registra».

**Suono per l'allarme critico** (in alto nell'elenco): impostazione predefinita dell'organizzazione per tutti i tipi senza suono proprio, di default il corno bitonale. Nel dialogo di un tipo vale «Impostazione predefinita dell'organizzazione», finché non scegli un altro suono o «Suono di sistema». Ogni persona può scegliere un proprio suono nel profilo dell'app nativa; la web app suona sempre il proprio suono.

Un nuovo tipo riprende le impostazioni di uno esistente. I tipi eliminati restano conservati presso i loro interventi.

![Tipi d'intervento](img/admin-einsatztypen.jpg)

### Opzioni d'intervento {#einrueckoptionen}

La scelta all'accettazione di un intervento. Per opzione: condivide la posizione, diritti della direzione dell'intervento, conta tra i soccorritori. Le opzioni vengono disattivate invece che eliminate; almeno una resta attiva.

![Opzioni d'intervento](img/admin-einrueckoptionen.jpg)

### Ruoli speciali {#spezialrollen}

Ruoli nell'intervento, per esempio direzione dell'intervento, bagnino di salvataggio o posto di sicurezza. Per ruolo: ordine, colore, **posto di sicurezza** (limita l'area di ricerca), può inviare messaggi, può parlare alla radio.

![Ruoli speciali](img/admin-spezialrollen.jpg)

### Soccorritori esterni {#externe}

Modelli per soccorritori esterni (nome, descrizione, ruolo speciale, sigla, colore, facoltativamente una posizione fissa). La direzione dell'intervento li sceglie durante la registrazione nell'intervento.

### Simboli d'intervento {#symbole}

Quali simboli tattici sono disponibili per i marcatori e se sono ammessi marcatori senza simbolo.

## Pianificazioni {#planungen}

Esercitazioni preparate, interventi secondari pianificati ed eventi liberi con i loro elementi, piano delle esercitazioni e iscrizioni, esecuzioni con registro e replay. Vedi [Esercitazioni e pianificazioni](#planung/vorbereiten).

## Funzioni {#funktionen-kat}

### Funzioni {#funktionen}

Attivabili e disattivabili per tipo d'intervento: area di ricerca, vittime, messaggi, marcatori, esterni, distanza fluviale, vista pubblica, registrazione, navigazione, luoghi fissi, livello & portata, info persona, livello di geodati, radio, veicoli. Le funzioni disattivate scompaiono subito dall'app d'intervento, anche nell'intervento in corso; i dati esistenti restano conservati.

![Funzioni per tipo d'intervento](img/admin-funktionen.jpg)

### Distanza fluviale {#flussdistanz}

Le velocità di confronto con cui lo strumento Distanza fluviale calcola i tempi di percorrenza (da 1 a 6 valori).

### Navigazione {#navigation}

Quali app di navigazione sono disponibili e quale è quella predefinita.

### Chiamata d'emergenza (SOS) {#sos}

Chi viene allertato da una chiamata d'emergenza: **direzione d'intervento e partecipanti nel raggio** (raggio in km), **solo la direzione d'intervento** o **tutti i partecipanti**. Inoltre per quanto tempo va premuto il pulsante (1–5 s), la durata del conto alla rovescia per annullare (0–10 s, 0 = nessuno) e se nella conferenza dal vivo suona un segnale acustico. La funzione stessa si attiva e disattiva per tipo d'intervento sotto **Funzioni**.

### Lingua {#sprache}

L'app esiste in tedesco, inglese, francese e italiano. Qui imposti l'**impostazione predefinita dell'organizzazione**. Vale per le persone che non hanno scelto una lingua e il cui dispositivo non usa nessuna di queste lingue, nonché per le notifiche push a persone che non hanno mai aperto l'app.

> Vengono tradotti i testi dell'app. Ciò che l'organizzazione registra essa stessa (tipi d'intervento, ruoli, opzioni d'intervento, messaggi, documenti) e il registro dell'intervento restano nella lingua in cui sono stati scritti.

### Assistente AI {#ai}

Fornitore (compatibile OpenAI o Anthropic), modello, chiave, limiti e protezione dei dati per tipo d'intervento (sostituire i nomi, arrotondare le coordinate). Nella matrice abiliti per funzione, ruolo e tipo d'intervento se l'assistente è spento, su richiesta o in accompagnamento.

## Mappa & dati {#karte-daten}

### Fornitori di mappe {#karten}

Quali mappe di base (immagini aeree, mappe) sono disponibili, il loro ordine e quella predefinita, globalmente e per tipo d'intervento. «Testa» carica una tessera di esempio.

![Fornitori di mappe](img/admin-karten.jpg)

### Coordinate {#koordinaten}

Il sistema di coordinate predefinito. Ogni persona può modificarlo nel profilo.

### Fonti di geodati {#geoquellen}

Da dove proviene la rete idrografica per l'area di ricerca e la distanza fluviale: propria banca dati PostGIS, API PostGIS e server Overpass, in un ordine impostabile, con memoria intermedia e statistica per server.

### Elicotteri {#helikopter}

Fonti dei dati ADS-B, filtro (elicotteri di soccorso, elicotteri, tutti), chi vede gli elicotteri, raggio con e senza intervento, elenco degli elicotteri di soccorso.

### Livello & portata {#pegel}

Raggio e numero delle stazioni di misura al PLS, laghi sì o no, fonti di dati con test.

### Luoghi fissi {#standorte}

Luoghi, aree e linee che compaiono sulla mappa in ogni intervento, per esempio punti di varo, magazzini o canali navigabili. Con categorie (mostrate o nascoste di default, prioritarie), proprietà (per esempio «sbarramento fluviale», che limita l'area di ricerca, o un avvertimento) e punti di navigazione (accessi).

![Luoghi fissi](img/admin-standorte.jpg)

### Riprendi geodati {#geodaten}

Cercare su una mappa oggetti lungo il corso d'acqua da OpenStreetMap (traverse, centrali, ponti, scivoli …) e riprenderli come luoghi fissi. L'app confronta regolarmente i luoghi ripresi con la fonte e segnala le differenze.

### Veicoli {#fahrzeuge}

Tipi di veicolo (barca, rimorchio, veicolo trainante) con posti e carico, veicoli con il loro beacon (UUID, major, minor, facoltativamente l'indirizzo MAC per il livello della batteria su iOS; i dispositivi Android lo imparano da soli) e l'ultimo livello della batteria segnalato, impostare la posizione a mano e la composizione (quale veicolo porta cosa).

## Valutazione {#auswertung-kat}

### Statistica e registro degli interventi {#einsatzlog}

Indicatori e tutti gli interventi con registro, replay, after action review, anonimizzazione ed «Elimina registrazione». Vedi [Valutazione](#auswertung/statistik).

### Configura after action review {#aarcfg}

Titolo, logo, campi del review, sezioni incluse, documenti ammessi per stato e il feedback sull'intervento con le sue domande.

### Statistica per persona {#personenstatistik}

Valutazione per persona con filtro, diagrammi e CSV.

### Consultazione per i soccorritori {#einsicht}

Cosa vedono i partecipanti sotto «I miei interventi»: spento, anonimo (predefinito) o completo, e quali pulsanti (registro, replay, AAR, Word, PDF).

### Anonimizzazione {#anonymisierung}

Rimuovere per livelli i dati personali degli interventi terminati: anonimizzare la visualizzazione, eliminare le registrazioni vocali, replay, registro, testi liberi, staccare le partecipazioni dall'account, eliminare la registrazione. Per livello e tipo d'intervento una scadenza per l'esecuzione automatica. Tranne la visualizzazione, i livelli sono irreversibili.

![Anonimizzazione](img/admin-anonymisierung.jpg)

## Note legali {#rechtliches}

Dati dell'organizzazione per le note legali e l'informativa sulla privacy. La pagina «Note legali, licenze & privacy» li completa con lo stato attuale delle impostazioni.

### Estratto sulla protezione dei dati {#datenschutzauszug}

Per le richieste d'accesso secondo la legge sulla protezione dei dati. Due diritti separati, entrambi nel gruppo «Protezione dei dati» dei ruoli utente; l'amministratore di sistema li ha sempre.

- **Estratto per persona** (diritto «Creare estratto sulla protezione dei dati per persona»): scegli la persona (anche disattivata), poi «Crea PDF» o «Crea ZIP». Il PDF contiene account e impostazioni, ruoli e gruppi, dispositivi connessi, assenze, orari di disponibilità, formazione, pianificazioni, partecipazioni con ultima posizione, voci nel registro dell'intervento, messaggi inviati e ricevuti, annunci, feedback sull'intervento, elementi registrati, uso dell'assistente AI e il registro dell'applicazione. Lo ZIP contiene inoltre tutti i dati come JSON e CSV, la cronologia delle posizioni come GeoJSON, l'immagine del profilo e le proprie registrazioni vocali.
- **Esportazione dei dati dell'organizzazione** (diritto «Creare esportazione dei dati dell'organizzazione»): «Panoramica come PDF» elenca tutte le tabelle con il numero di righe e tutte le persone; «Crea ZIP» contiene per ogni tabella un file JSON e uno CSV, su richiesta anche le registrazioni vocali e gli after action review salvati.

> Password, chiavi e token d'accesso non sono mai inclusi. L'estratto viene creato nel browser e non è salvato da nessuna parte; ogni estratto creato compare nella **cronologia** (chi, quando, quale persona, quale formato).

> ! Un estratto contiene nomi, posizioni e contenuti. Trasmetterlo solo in modo sicuro alla persona autorizzata.

Gli interventi le cui partecipazioni sono state anonimizzate e staccate dall'account non sono più associati alla persona e mancano quindi nell'estratto.

## Documenti {#dokumente-kat}

L'archivio documenti mette a disposizione la documentazione necessaria in intervento, in esercitazione e in formazione: piani d'intervento, liste di controllo, promemoria, regolamenti. Le persone con il diritto «Gestire l'archivio documenti» vedono tutto il necessario. Chi fa parte della **redazione** di una cartella vede l'archivio e i documenti in entrata solo per le sue cartelle. La funzione «Documenti» può essere disattivata per tipo d'intervento sotto [Funzioni](#funktionen).

### Archivio {#dokumente}

A sinistra c'è l'**albero delle cartelle** con il numero di voci per cartella (sottocartelle comprese), più «Tutti i documenti» e «Revisione dovuta». 🔒 contrassegna le cartelle che non tutti vedono. A destra in alto c'è la scheda della cartella scelta con i suoi **accessi** su una riga (chi la vede, redazione, proposte, per quante persone è visibile), sotto le sue voci come schede: tipo (PDF, TESTO, LINK …), versione, offline, immagini, conferma di lettura, «Revisione dovuta», file o indirizzo, dove compare in intervento e la data.

- **+ Voce** apre il dialogo con le schede File, Pagina e Link; **Carica …** crea più file in una volta nella cartella scelta (titolo = nome del file); **+ Cartella** crea una sottocartella di quella scelta.
- **Trascinare**: trascinare voci in un'altra cartella le sposta; le cartelle (con il diritto «Gestire l'archivio documenti») si possono trascinare in un'altra cartella o su «Tutti i documenti» (livello superiore). Il dispositivo ricorda le cartelle aperte.
- **Ricerca** «Cerca in tutte le cartelle …» trova titoli, parole chiave, descrizioni e il testo di pagine e PDF, con il passaggio trovato.

Una voce è un **file** (con versioni), una **pagina** (testo direttamente nell'app, Markdown come in queste istruzioni, con immagini) o un **link**. Per voce: titolo, descrizione, parole chiave, tipi d'intervento («Per questo intervento»), luoghi fissi (il documento compare nel popup del luogo), «Rivedere entro», offline e conferma di lettura.

- **Cartella** («Modifica cartella»): a sinistra nome, cartella superiore, descrizione e chi la vede (tutti gli utenti connessi o solo determinati gruppi d'intervento o ruoli utente, con il numero di persone); una sottocartella può solo restringere ulteriormente. A destra la **redazione** (può creare e modificare voci), chi può **proporre** e se la cartella è **condivisibile con esterni**.
- **Voce** («Modifica»): a sinistra tipo, titolo, cartella, parole chiave, descrizione e il contenuto (file con versioni, testo della pagina o indirizzo), a destra «Offri in intervento» (tipi d'intervento e luoghi fissi con «+ Luogo»), «Rivedere entro», conferma di lettura («Configura …», «Vedi stato») e offline («può essere salvato offline», «salva automaticamente offline per tutti i lettori»). Disattivare e attivare direttamente sulla scheda.
- **Versioni**: un nuovo file sostituisce il precedente. Le versioni precedenti restano conservate secondo le [impostazioni](#dokumente-einstellungen) e si possono ripristinare. Il testo dei PDF viene estratto al caricamento per la ricerca.
- **Conferma di lettura**: da tutti coloro che vedono la voce, solo da determinati gruppi d'intervento o ruoli utente, o come l'obbligo di una competenza della [formazione](#ausbildung). Chi si aggiunge più tardi deve a scelta anche confermare (con una propria scadenza dall'ingresso) oppure no. Scadenza in giorni con promemoria via push. Una nuova versione richiede di nuovo la conferma, a scelta solo per modifiche sostanziali. Con una competenza la conferma registra la formazione presso la persona. La panoramica mostra conferme aperte, confermate e volontarie, con «Ricorda» e CSV.

> Chi può vedere una voce lo regola la cartella. Chi deve confermarla lo regola la voce. Tutti gli altri lettori possono confermare volontariamente.

### In entrata {#dokumente-eingang}

Proposte dei soccorritori: una nuova voce, una nuova versione o un'indicazione («Radio mancante nella lista di controllo»). Le vede solo la redazione della cartella. **Applica** crea la voce o la versione, **Rifiuta** con motivazione, le indicazioni si contrassegnano come **evase**. La persona che propone viene notificata (push) e vede lo stato nel profilo.

### Visualizzazione {#dokumente-anzeige}

Quali sezioni compaiono nella finestra «Documenti» dell'app d'intervento e in quale ordine: conferma di lettura aperta, preferiti, nei luoghi dell'area d'intervento, per questo intervento, aperti di recente, nuovi e modificati, cartelle. C'è un'impostazione predefinita (anche senza intervento) e, se desiderato, una propria per tipo d'intervento. Inoltre il numero di voci per sezione, il numero nella scheda del menu «Documentazione» e se le persone possono disporre le sezioni per sé.

### Impostazioni {#dokumente-einstellungen}

- **Tipi di file**: catalogo di tipi fissi, ognuno attivabile, più estensioni libere. L'app non visualizza mai le estensioni libere, vengono solo scaricate. I file eseguibili e attivi (p. es. `.exe`, `.js`, `.html`, `.svg`) non sono mai ammessi. Il server controlla il contenuto, non solo l'estensione.
- **Memoria**: dimensione massima per file, memoria complessiva, numero di versioni precedenti, offline per dispositivo.
- **Esterni**: se la direzione dell'intervento può condividere documenti con esterni, sulla pagina ospite della radio e/o con un link proprio, se gli esterni possono scaricare e quanti accessi all'ora sono ammessi.

## Connettività {#konnektivitaet}

### Allarme via e-mail {#emailalarm}

Un'e-mail in arrivo avvia un intervento: casella di posta (IMAP), recupero, mittenti ammessi e parole chiave per tipo d'intervento, convocazione e PLS da coordinate nell'e-mail. «Verifica esempio» testa un'e-mail senza avviare un intervento.

### Radio & conferenza {#funk}

Annunci e conferenza in diretta (server LiveKit), canali con diritti di ascolto e di parola, trascrizione, conservazione e link per esterni per tipo d'intervento.

### Organizzazioni partner {#partner}

Accordi con altre organizzazioni che gestiscono una **propria installazione** con un proprio indirizzo (diritto «Configurare la connettività»). Non c'è una banca dati comune: ogni organizzazione tiene le proprie persone, impostazioni e interventi sul proprio server e decide da sé cosa può fare un partner.

1. Una parte registra il partner con **+ Organizzazione partner**: nome, indirizzo dell'installazione (p. es. `https://partner.example.ch/swiftwater/`, si trova presso il partner in alto sotto «Questa installazione») e **Genera nuova chiave**. La chiave compare una volta da copiare.
2. L'altra parte registra questa installazione allo stesso modo e inserisce sotto «Chiave» la **chiave ricevuta**. Trasmettere la chiave per una via sicura (non per e-mail ordinaria); autorizza la convocazione.
3. **Testa la connessione** da entrambe le parti; dopo ogni parte conosce i tipi d'intervento del partner.
4. Nel dialogo del partner stabilire **quali gruppi d'intervento** può convocare (tutti o selezionati) e **quale proprio tipo d'intervento** segue un tipo d'intervento del partner. I tipi d'intervento senza associazione non attivano nulla da noi.

I server comunicano direttamente tra loro; ogni richiesta è firmata con la chiave e vale solo per breve tempo (gli orologi di entrambi i server devono essere esatti). **Disattiva** blocca subito il partner senza eliminare l'accordo; **Rimuovi** è possibile solo finché non è in corso un intervento comune. Una nuova chiave sostituisce la vecchia, il partner deve poi inserirla anche lui. Svolgimento in intervento: vedi [Convocazione e convocazione supplementare](#leitung/aufgebot).

> I partner vedono di noi solo ciò di cui ha bisogno un intervento comune: nome e ruolo speciale dei partecipanti con posizione, la situazione, i luoghi fissi e i veicoli di questo intervento, le vittime comuni e il canale comune. I numeri di telefono solo se da noi la chiamata diretta è aperta a tutti i partecipanti.

## Sistema {#system}

### Registro dell'applicazione {#applog}

Protocollo tecnico di server e dispositivi: eventi, avvertimenti ed errori, con filtro, ricerca ed esportazione. Aiuta nella ricerca degli errori; non contiene password, posizioni o contenuti degli interventi.

![Registro dell'applicazione](img/admin-applog.jpg)

### Versioni {#versionen}

Versione del server, versione minima delle app e tutte le app connesse per piattaforma e versione.

### Elimina definitivamente {#endgueltig}

Alcune cose normalmente si possono solo disattivare o non eliminare affatto, affinché i dati degli interventi restino completi. Chi oltre al diritto della sezione ha il diritto speciale **«Elimina definitivamente»** (ruoli utente, gruppo Sistema) vi vede il pulsante **«Elimina definitivamente»**:

| Cosa | Dove | Conseguenze |
|---|---|---|
| Intervento terminato | Statistica degli interventi (diritto «Valutare tutti gli interventi») | Intervento con registro, messaggi, registrazione, vittime, marcatori, annunci, after action review (anche PDF salvato) e feedback; un'esercitazione preparata viene reimpostata |
| Utente | Utenti | Account e tutti i dati personali; negli interventi passati la persona compare come «Persona eliminata», anche in registro e messaggi |
| Opzione d'intervento | Opzioni d'intervento | le partecipazioni passate mostrano solo la sigla; per categoria ne resta attiva almeno una |
| Ruolo speciale | Ruoli speciali | soccorritori passati, modelli, elementi di esercitazione e canali radio perdono il ruolo |
| Modello | Soccorritori esterni | gli esterni già registrati restano |
| Competenza con voci | Formazione | le formazioni registrate di tutte le persone |
| Tipo di veicolo con veicoli, veicolo da interventi | Veicoli | i veicoli con avvistamenti e composizioni, anche nel replay |

Prima dell'eliminazione una richiesta di conferma elenca le conseguenze. **Non si può annullare.** Gli interventi in corso e il proprio account non si possono eliminare. Ogni eliminazione definitiva compare nel registro dell'applicazione (senza nomi).

> ! Per i dati personali negli interventi l'[anonimizzazione](#anonymisierung) è di solito la via migliore: conserva statistica e registro dell'intervento.
