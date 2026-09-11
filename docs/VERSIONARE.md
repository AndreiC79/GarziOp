# Versionarea platformei

## 2026.08.11.7.5 / FTP V7.5

- Operatiile EXTRA pot fi repartizate pe sali.
- Repartizarea este disponibila asistentelor, medicilor ATI si conturilor cu control complet.
- Marcajul EXTRA si starea aprobarii raman vizibile dupa repartizare.
- Operatia poate reveni in rubrica EXTRA daca sala a fost aleasa gresit.
- Fara modificari SQL.

## 2026.08.11.7.4.3 / FTP V7.4.3

- Butonul Adauga este afisat in antetul dialogului mobil, langa Inchide.
- La editare, acelasi buton devine Salveaza.
- Butonul nu mai depinde de zona inferioara a ecranului.
- Fara modificari SQL.

## 2026.08.11.7.4.2 / FTP V7.4.2

- Dialogul Programului operator detecteaza afisarea in interiorul platformei.
- Butoanele de actiune raman deasupra meniului mobil principal.
- Se pastreaza adaptarea la tastatura si bara de sugestii introdusa in V7.4.1.
- Fara modificari SQL.

## 2026.08.11.7.4.1 / FTP V7.4.1

- Corectie incrementala pentru dialogul Adauga programare pe mobil.
- Formularul urmareste zona vizibila reala cand este deschisa tastatura.
- Campurile au scroll separat, iar butoanele raman permanent accesibile.
- Fara modificari SQL.

## 2026.08.11.7.4 / FTP V7.4

- Program operator optimizat pentru vizualizarea pe telefon.
- Ordine mobila: calendar, operatii programate, statistici.
- Carduri de operatie compacte si usor de citit.
- Dialoguri cu scroll intern si butoane accesibile pe ecrane scurte.
- Fara modificari SQL sau schimbari ale structurii bazei de date.

## 2026.08.11.7.3 / FTP V7.3

- Centru de validare operational pe dashboard, cu acces diferentiat dupa rol si drepturile existente.
- Validari pentru garzi consecutive, linii neacoperite, operatii EXTRA in asteptare, programari nerepartizate si operatii trecute nebifate.
- Toate grupele din Administrare sunt inchise implicit.
- Nu necesita modificari SQL sau schimbari ale structurii bazei de date.

Versiunea foloseste formatul `AAAA.LL.ZZ.N`. O actualizare incrementala a aceluiasi pachet poate folosi forma `AAAA.LL.ZZ.N.P`.

- `AAAA.LL.ZZ` este data la care a fost pregatit update-ul.
- `N` este numarul update-ului din ziua respectiva, incepand cu `1`.
- `P` este numarul corectiei incrementale, cand exista.
- Exemplu: `2026.08.09.4` corespunde arhivei FTP `V4` din 09.08.2026.

## Regula pentru fiecare update

1. Se modifica versiunea si descrierea in `app/version.php`.
2. Arhiva se numeste `INTERN_UPDATE_FTP_AAAA-LL-ZZ_VN.zip` sau `..._VN.P.zip` pentru o corectie incrementala.
3. Aceeasi versiune se scrie in instructiunile FTP, manifest si jurnalul de modificari.
4. Dupa incarcarea prin FTP se verifica versiunea pe pagina de autentificare sau la adresa `public/version.php`.
5. Un pachet modificat nu se publica sub acelasi numar de versiune; se genereaza intotdeauna versiunea urmatoare.

Versiunea nu este pastrata in baza de date si poate fi actualizata numai prin fisierele incarcate prin FTP.
