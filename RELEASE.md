# Procedura di rilascio di una versione
Viene usato il semantic versioning come standard abbinato al file CHANGELOG.md
1) allineare `Version:` nell'header di `onpage.php` e la sezione `[Unreleased]`
   di `CHANGELOG.md` alla versione da rilasciare (titolo `## [X.Y.Z] - AAAA-MM-GG`
   con la data del rilascio, e link di confronto in fondo al file)
2) git tag -a "vX.Y.Z" -m "vX.Y.Z"
3) git push origin --tags
4) costruire lo zip di distribuzione con lo script, passando il tag:

   ```
   ./build-zip vX.Y.Z
   ```

   Lo script prende dal tag solo cio' che serve a WordPress (`onpage.php`,
   `routes.php`, il codice di `src/` tranne `src/Tests/`, `LICENSE`,
   `CHANGELOG.md`), li mette in una cartella
   `onpage` — WordPress ne ricava lo slug del plugin — e scrive
   `dist/onpage-X.Y.Z.zip`, verificando che la versione dentro il pacchetto sia
   quella del nome. La documentazione resta fuori: sta online, e spedirla
   significherebbe consegnare a clienti e partner anche gli interni del plugin.

5) Andare sulla repo GitHub (`onpage-dev/wordpress-plugin`, non GitLab) e creare
   una Release a partire da questo tag, allegando lo zip
6) la repo e' privata, quindi il link della Release non e' scaricabile dai clienti:
   caricare lo stesso zip sullo storage di On Page® e sostituire il link di download
   nella sezione **WordPress** della documentazione pubblica, in tutte le lingue,
   insieme al numero di versione indicato nella stessa nota
