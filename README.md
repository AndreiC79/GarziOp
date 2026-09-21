# Intern Maternitatea Giulesti

Aplicatie interna unica pentru:

- planificarea garzilor OG, ATI si NN;
- programarea operatiilor (ProgOp);
- administrarea utilizatorilor, drepturilor, rapoartelor si jurnalului de activitate.

Proiectele existente `C:\xampp\htdocs\garzi` si `C:\xampp\htdocs\ProgOp` raman nemodificate si vor fi folosite ca surse pentru migrarea datelor si a functionalitatilor.

## Cerinte

- PHP 8.1 sau mai nou;
- MySQL 8 / MariaDB 10.5 sau mai nou;
- extensia PHP PDO MySQL;
- Apache cu `mod_rewrite` optional.

## Instalare locala

1. Creeaza o baza de date MySQL cu setul de caractere `utf8mb4`.
2. Importa `database/schema.sql`.
3. Copiaza `config/config.example.php` ca `config/config.php` si completeaza accesul la baza de date.
4. Configureaza Apache cu director public `public/` sau deschide `http://localhost/intern/public/`.

Fisierul `config/config.php` nu trebuie urcat intr-un repository si nu trebuie trimis impreuna cu exporturile bazei de date.

## API / MCP

Schema pentru conectarea unui client MCP este publicata la `public/api/openapi.php`. Autentificarea foloseste un token Bearer creat din **Administrare > Acces API / AI**.

Repartizarea medicilor ATI pe sali este expusa explicit prin:

- `listAtiRoomAssignments` pentru citirea salilor, medicilor ATI activi si repartizarilor dintr-un interval;
- `modifyAtiRoomAssignments` pentru inlocuirea medicilor unei sali intr-o anumita zi.

Exemplu de corp pentru modificare:

```json
{
  "assignments": [
    {
      "date": "2026-09-22",
      "room_id": 1,
      "person_id": 49
    }
  ]
}
```

ID-urile salilor si medicilor ATI sunt returnate de `listAtiRoomAssignments`. Pentru doi medici in aceeasi sala si zi se trimit doua elemente cu aceleasi `date` si `room_id`. Modificarea necesita un token cu dreptul `progop.manage_all`.

## Directoare

- `app/` - servicii PHP comune;
- `config/` - configurare locala;
- `database/` - schema si migrari;
- `docs/` - specificatia functionala;
- `public/` - singurul director accesibil din browser;
- `storage/reports/` - rapoarte generate si arhivate.

## Etape de migrare

1. Autentificare, conturi si drepturi.
2. Import personal si garzi din proiectul vechi.
3. Generare si versiuni pentru Linia 1 OG.
4. Schimburi, cedari/preluari, notificari si istoric.
5. Migrare ProgOp si evidentierea zilelor proprii de garda.
6. Rapoarte lunare/anuale si procedura de arhivare a logurilor.
