# Specificatie functionala

## Platforma

Aplicatia este o singura platforma cu autentificare comuna si trei module:

- Garzi;
- Program operator (ProgOp);
- Administrare.

Interfata este responsive. Pe desktop foloseste navigare laterala si panouri ample, iar pe telefon foloseste navigare compacta, carduri verticale si actiuni potrivite pentru atingere. Nu se mentin doua aplicatii UI separate.

## Utilizatori si securitate

- Fiecare persoana are cont individual cu username si parola.
- Parolele se stocheaza numai ca hash securizat.
- Administratorul poate reseta parola, dar nu o poate vedea.
- Parola temporara trebuie schimbata la prima autentificare.
- Contul se blocheaza temporar dupa incercari repetate nereusite.
- Un utilizator poate fi asociat cu un medic din lista unica de personal.
- Medicii si utilizatorii se dezactiveaza, nu se sterg, pentru pastrarea istoricului.

## Drepturi

Drepturile sunt acordate prin roluri si pot fi ajustate individual. Setul initial include:

- vizualizare Garzi;
- administrare personal si ordine OG;
- generare, publicare, restaurare si corectie administrativa a garzilor;
- vizualizare ProgOp;
- creare, modificare, mutare, anulare si stergere interventii;
- administrare sali si tipuri de operatii;
- vizualizare loguri;
- generare/descarcare rapoarte;
- stergere loguri arhivate;
- administrare utilizatori si drepturi.

Medicii ATI au control operational complet in ProgOp. Medicii NN au numai vizualizare ProgOp. Autorul unei programari isi poate modifica programarea, in limitele drepturilor stabilite.

## Garzi OG Linia 1

- Exista o lista ordonata de medici OG activi.
- Lista poate fi ordonata alfabetic dupa numele de familie sau reordonata manual de administrator.
- Un medic nou intra implicit in pozitia alfabetica si poate fi mutat ulterior.
- Generarea acopera fiecare zi calendaristica consecutiv, fara reguli de indisponibilitate.
- Administratorul alege data de inceput, numarul de luni si optional medicul de inceput.
- Implicit, o generatie noua continua cu medicul urmator din rotatia precedenta.
- Programul generat este Draft si devine vizibil oficial dupa Publicare.
- Linia 1 publicata este blocata pentru editare directa.
- Regenerarea creeaza o versiune noua si pastreaza versiunile anterioare pentru restaurare.
- Restaurarea schimba versiunea activa, fara sa stearga istoricul actiunilor ulterioare.
- NN si ATI nu folosesc acest algoritm; raman pe fluxul de completare curent.

## Schimb si cedare/preluare

- Un schimb implica doua garzi viitoare, din orice luni.
- O cedare/preluare muta o singura garda, fara o garda oferita la schimb.
- Nu se pot modifica garzi din trecut.
- Un medic poate actiona numai asupra unei garzi pe care o detine efectiv.
- Un medic nu poate ajunge pe doua linii de garda in aceeasi zi.
- Operatiile nu necesita aprobarea administratorului.
- Administratorul poate face orice corectie prin actiunea dedicata; motivul este optional.
- Se pastreaza separat medicul initial si medicul care efectueaza efectiv garda.
- Fiecare schimb, cedare, preluare, restaurare si corectie este logata si produce notificari in aplicatie.
- Fisa unei garzi afiseaza intregul traseu: titular initial, titular curent, persoane implicate, date si utilizatori.

## Calendar si sarbatori

- Calendarul marcheaza weekendurile si sarbatorile legale.
- Sarbatorile au data si denumire si sunt configurabile pentru fiecare an.
- In ProgOp sunt evidentiate distinct zilele in care medicul autentificat este de garda.
- O garda poate avea simultan marcaj de weekend si sarbatoare, dar este numarata o singura data in total.

## ProgOp

- Pastreaza salile, tipurile de operatie, interventiile, mutarile si starile existente.
- Fiecare interventie retine autorul programarii pentru statistici si audit.
- Medicii ATI pot decide si modifica programul operator complet.
- Operatiile sterse/anulate raman in istoric pentru trasabilitate si raportare separata.

## Audit si notificari

- Auditul retine utilizatorul, momentul, actiunea, tipul si identificatorul inregistrarii, valorile anterioare/noi si adresa IP.
- Logurile nu se modifica din interfata.
- Notificarile au stare citita/necitita si raman in istoricul utilizatorului.
- Utilizatorul vede lunar propriile garzi initiale, schimbate, date si preluate, inclusiv persoanele implicate.

## Rapoarte

Rapoartele lunare si anuale se exporta XLSX si se arhiveaza in aplicatie. Pot include:

- medicul;
- numarul de garzi efectuate;
- distributia pe zile, linii, weekenduri si sarbatori;
- numarul de schimburi, garzi preluate si garzi date;
- tipurile de operatii programate de medic;
- operatii efectuate, anulate sau sterse, in categorii separate.

Administratorul poate solicita stergerea logurilor unui an numai dupa generarea si arhivarea raportului anual. Se pastreaza permanent dovada operatiei: raportul, utilizatorul, momentul si perioada stearsa.

## Publicare si gazduire

- Codul se poate distribui prin FTP.
- Schema si exportul bazei de date se livreaza separat administratorului serverului.
- Configurarea bazei de date si secretele raman intr-un fisier local care nu se distribuie public.
- Directorul web public trebuie sa fie `public/`, pentru ca fisierele de configurare si rapoartele sa nu fie accesibile direct.
