# Procedura di rilascio di una versione

Viene usato il [Semantic Versioning](https://semver.org/lang/it/) abbinato al file
`CHANGELOG.md`.

1) allineare `Version:` nell'header di `onpage.php` alla versione da rilasciare e
   aggiungere in cima a `CHANGELOG.md` la sua sezione, con titolo
   `## [X.Y.Z] - AAAA-MM-GG` e la data del rilascio, piu' il link della nuova
   versione in fondo al file
2) git tag -a "vX.Y.Z" -m "vX.Y.Z"
3) git push origin --tags
4) costruire lo zip di distribuzione a partire dal tag:

   ```
   git archive --format=zip --prefix=onpage/ -o onpage-X.Y.Z.zip vX.Y.Z \
       onpage.php routes.php LICENSE CHANGELOG.md src ':(exclude)src/Tests'
   ```

   Nel pacchetto entra solo cio' che serve a WordPress (`onpage.php`,
   `routes.php`, il codice di `src/` tranne `src/Tests/`, `LICENSE`,
   `CHANGELOG.md`), dentro una cartella `onpage` — WordPress ne ricava lo slug
   del plugin. La documentazione resta fuori: sta online, e spedirla
   significherebbe consegnare a clienti e partner anche gli interni del plugin.

5) Andare sulla repo GitHub e creare una Release a partire da questo tag,
   allegando lo zip
6) la repo e' privata, quindi il link della Release non e' scaricabile dai clienti:
   caricare lo stesso zip sullo storage di On Page® e sostituire il link di download
   nella sezione **WordPress** della documentazione pubblica, in tutte le lingue,
   insieme al numero di versione indicato nella stessa nota
