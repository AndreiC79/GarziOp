# Stagii externe ale rezidentilor

Fisierul `storage/config/resident_stages.json` foloseste date ISO `AAAA-LL-ZZ`; intervalul include ambele capete si nu modifica baza de date.

```json
{"format_version":1,"stages":[{"person_id":123,"display_name":"NUME PRENUME","start_date":"2026-09-01","end_date":"2026-10-31","hospital":"Spital extern","notes":"optional"}]}
```

`person_id` este recomandat, iar `display_name` este rezerva. In interval, operatia este permisa daca rezidentul este de garda sau daca se confirma programarea dupa ora 14:00. Confirmarea intra in jurnalul operatiei.
