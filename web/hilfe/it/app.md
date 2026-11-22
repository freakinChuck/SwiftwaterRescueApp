# App nativa (Android e iOS)

L'app nativa «Swiftwater Rescue» per Android e iOS offre la stessa app d'intervento del browser, e in più:

- **allarmi critici** a schermo intero con suoneria, anche con «Non disturbare» e telefono silenzioso,
- **condivisione della posizione in background**, anche a schermo bloccato,
- **più organizzazioni** (server) in un'unica app,
- **veicoli** tramite beacon Bluetooth.

L'app apre l'amministrazione, «I miei interventi» e queste istruzioni come pagina web; non devi accedere di nuovo per questo.

## Account {#konten}

Al primo avvio aggiungi un account: indirizzo del server, nome utente e password, facoltativamente una denominazione propria. Altri account (altre organizzazioni) li aggiungi nell'elenco degli account. Per ogni account attivi se viene monitorato («attivo»).

- All'avvio l'app apre l'ultimo account usato.
- Ritorno all'elenco degli account: tocca il nome del server con ⇄ nell'intestazione del menu oppure «Cambia account / server» nel profilo.
- L'elenco degli account mostra per ogni account se la posizione ha potuto essere condivisa l'ultima volta.
- Con **Prova la demo** nell'elenco degli account avvii la [modalità demo](#start/demo) anche nell'app.

## Lingua {#sprache}

Sotto **Impostazioni** (elenco degli account) e nel profilo, scheda «Visualizzazione», scegli la lingua dell'app: tedesco, inglese, francese, italiano o «Automatica» (lingua del dispositivo). Vale per tutti gli account e per le notifiche; l'app la comunica ai server, così le notifiche push arrivano nella stessa lingua.

## Allarme e monitoraggio {#hintergrund}

L'app interroga regolarmente tutti gli account attivi e segnala allarmi, messaggi a te, annunci e la fine dell'intervento. Con il push configurato le notifiche arrivano subito anche ad app chiusa.

- **Android**: senza push o durante un intervento è attivo un servizio in background con una notifica permanente. «Interroga sempre in background» nel profilo lo mantiene sempre attivo.
- **iOS**: le notifiche ad app chiusa arrivano solo tramite push.

## Allarmi critici {#kritisch}

Per i tipi d'intervento con livello d'allarme «critico» l'app mostra l'allarme a schermo intero e suona finché non lo confermi (al massimo 5 minuti).

- **Android**: consenti all'app l'accesso a **«Non disturbare»**, affinché l'allarme suoni anche allora. L'app lo chiede.
- **iOS**: le notifiche critiche richiedono l'autorizzazione per gli «Avvisi critici».
- Il suono lo scegli nel profilo sotto «Allarme» per tipo d'intervento (corni, sirene, melodie) e lo ascolti con «Prova».

## Posizione in background {#standort}

Affinché l'app condivida la tua posizione in intervento anche a schermo bloccato, le serve l'autorizzazione alla posizione **«Consenti sempre»**. L'app la chiede dopo l'accettazione di un intervento. Senza questa autorizzazione condivide la posizione solo finché è aperta.

Dopo la fine dell'intervento o quando lasci l'intervento, l'app smette di condividere la posizione.

## Radio {#funk}

Annunci e conferenza in diretta funzionano nell'app come nel browser (vedi [Radio](#einsatz/funk)). L'app ha bisogno per questo dell'autorizzazione al microfono. Su Android la conferenza continua anche a schermo bloccato.

## Veicoli e beacon {#beacons}

In un intervento con veicoli l'app chiede una volta il Bluetooth. Riconosce i beacon dei veicoli vicini, ne segnala la posizione e ti associa al veicolo in cui sei seduto. Al di fuori degli interventi segnala i beacon solo con il tuo consenso nel profilo.

**Scansiona veicoli ora** (nella finestra «Soccorritori» sotto «Veicoli» e nel profilo, scheda «Veicoli») cerca subito i beacon vicini, mostra i veicoli trovati con l'intensità del segnale e li segnala: in intervento con la tua posizione, altrimenti senza il tuo nome (solo se l'amministrazione consente le segnalazioni al di fuori degli interventi). Utile per verificare se un beacon funziona.

**Livello della batteria:** i beacon DX-Smart lo inviano se nella loro app di configurazione è attivato il frame «Device Info» (lasciare attivato il frame «iBeacon»). Funzionano anche i beacon con Eddystone-TLM: inviano la tensione e l'app ne stima la percentuale (3,0 V = pieno, 2,0 V = vuoto). Su iOS serve inoltre il frame «Device Info». L'app lo legge a ogni scansione; compare presso il veicolo (dal 20 % con «⚠ Batteria scarica») e nella panoramica di «Scansiona veicoli ora». Lì compaiono anche i beacon vicini che non appartengono a nessun veicolo, con il loro indirizzo MAC. Su iOS l'app associa il livello della batteria solo tramite l'indirizzo MAC del veicolo: un dispositivo Android lo impara alla prima scansione, altrimenti lo inserisce l'amministrazione presso il veicolo.

> ! Su iPhone l'app trova i beacon solo con la **Posizione esatta** (Impostazioni → Swiftwater Rescue → Posizione). Se è disattivata, il profilo lo indica sotto «Veicoli», con il pulsante **Consenti posizione esatta**. Al di fuori degli interventi serve inoltre «Sempre».

## Aggiornamenti {#updates}

Se la versione della tua app è troppo vecchia per il server, compare un avviso con **Aggiorna** (Google Play o TestFlight). **Novità** nel profilo mostra le modifiche.
