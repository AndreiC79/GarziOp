<?php

declare(strict_types=1);

$helpCanSeeGuards = in_array('guards.view', $permissions, true);
$helpCanEditGuards = count(array_filter($permissions, static fn (string $permission): bool => str_starts_with($permission, 'guards.') && $permission !== 'guards.view')) > 0;
$helpCanSeeProgOp = in_array('progop.view', $permissions, true);
$helpCanEditProgOp = count(array_intersect(['progop.create', 'progop.edit_own', 'progop.manage_all'], $permissions)) > 0;
?>

<section class="help-page">
    <header class="help-hero">
        <div>
            <span class="eyebrow">Ghidul utilizatorului</span>
            <h2>Cum folosesti platforma</h2>
            <p>Alege capitolul de care ai nevoie. Fiecare instructiune urmeaza exact pasii din aplicatie.</p>
        </div>
        <div class="help-access-summary">
            <span class="<?= $helpCanSeeGuards ? 'available' : 'unavailable' ?>">Garzi <?= $helpCanSeeGuards ? 'disponibile' : 'fara acces' ?></span>
            <span class="<?= $helpCanSeeProgOp ? 'available' : 'unavailable' ?>">Program operator <?= $helpCanSeeProgOp ? 'disponibil' : 'fara acces' ?></span>
        </div>
    </header>

    <nav class="help-quick-nav" aria-label="Capitole ajutor">
        <a href="#help-start">Primii pasi</a>
        <?php if ($helpCanSeeGuards): ?><a href="#help-guards">Garzi</a><?php endif; ?>
        <?php if ($helpCanSeeProgOp): ?><a href="#help-operations">Operatii</a><?php endif; ?>
        <a href="#help-account">Cont si notificari</a>
        <a href="#help-errors">Probleme frecvente</a>
    </nav>

    <div class="help-chapters">
        <details class="help-chapter" id="help-start" open>
            <summary><span class="help-number">01</span><span><strong>Primii pasi</strong><small>Dashboard, navigare si luna afisata</small></span></summary>
            <div class="help-chapter-body">
                <ol class="help-steps">
                    <li><b>Autentifica-te</b> cu utilizatorul si parola personala. La prima conectare, aplicatia iti poate cere schimbarea parolei temporare.</li>
                    <li><b>Foloseste meniul din stanga</b> pentru Vedere generala, Garzi, Program operator si Ajutor. Pe telefon, meniul este jos.</li>
                    <li><b>Verifica dashboardul:</b> cardurile arata garzile tale viitoare, operatiile de azi si notificarile necitite. Apasa pe un card pentru detalii.</li>
                    <li><b>Schimba luna</b> din selectorul paginii. La fiecare deschidere, Garzile si Programul operator pornesc cu luna curenta.</li>
                </ol>
                <figure class="help-visual dashboard-visual">
                    <figcaption>Imagine explicativa: cardurile de pe Vedere generala sunt scurtaturi.</figcaption>
                    <div class="help-mini-sidebar"><i>MG</i><span>P</span><span>G</span><span>OP</span><span>?</span></div>
                    <div class="help-mini-content"><b>Buna, Andrei</b><div><span>Garzile mele<br><strong>3</strong></span><span>Operatii azi<br><strong>5 / 1</strong></span><span>Notificari<br><strong>2</strong></span></div></div>
                </figure>
            </div>
        </details>

        <?php if ($helpCanSeeGuards): ?>
        <details class="help-chapter" id="help-guards">
            <summary><span class="help-number">02</span><span><strong>Garzi: vizualizare si adaugare</strong><small>Personal, specialitate, linie si zi</small></span></summary>
            <div class="help-chapter-body">
                <?php if (!$helpCanEditGuards): ?><p class="help-permission-note">Contul tau poate vedea garzile, dar nu are drept de modificare. Pasii de mai jos iti arata si cum lucreaza persoanele cu drept de editare.</p><?php endif; ?>
                <ol class="help-steps">
                    <li>Deschide <b>Garzi</b> si alege specialitatea <b>OG, ATI sau NN</b>.</li>
                    <li>Din zona <b>Personal</b>, cauta si selecteaza medicul. Medicul selectat este evidentiat.</li>
                    <li>Alege sus linia dorita: <b>L1, L2, L3...</b>. Pentru OG, Linia 1 este protejata; celelalte linii se completeaza direct.</li>
                    <li>Apasa pe ziua libera dorita. Verifica medicul, data si linia in fereastra de confirmare, apoi salveaza.</li>
                    <li>Daca slotul este ocupat pe o linie obisnuita, apasa pe nume pentru optiunile de <b>inlocuire</b> sau <b>stergere</b>.</li>
                </ol>
                <figure class="help-visual guards-visual">
                    <figcaption>Imagine explicativa: ordinea selectiei pentru adaugarea unei garzi.</figcaption>
                    <div class="help-person"><em>1</em><span>Personal</span><strong>CUCU Andrei</strong></div>
                    <div class="help-lines"><em>2</em><span>L1</span><b class="active">L2</b><span>L3</span></div>
                    <div class="help-days"><em>3</em><span><b>12</b><small>liber</small></span><span class="picked"><b>13</b><small>click aici</small></span><span><b>14</b><small>MAIER</small></span></div>
                </figure>
            </div>
        </details>

        <details class="help-chapter">
            <summary><span class="help-number">03</span><span><strong>Garzi: stergere, schimb, cedare si preluare</strong><small>Regulile speciale pentru OG Linia 1</small></span></summary>
            <div class="help-chapter-body help-two-column">
                <section>
                    <h3>Stergerea unei garzi</h3>
                    <ol class="help-steps compact">
                        <li>Apasa direct pe numele medicului din calendar.</li>
                        <li>Pe liniile obisnuite, alege <b>Sterge garda</b>.</li>
                        <li>Confirma actiunea. Daca ai gresit, foloseste <b>Undo</b> cat timp optiunea este disponibila.</li>
                    </ol>
                    <p class="help-warning">OG Linia 1 nu se sterge direct de un cont medical obisnuit. Acolo se folosesc schimbul, cedarea sau preluarea.</p>
                </section>
                <section>
                    <h3>Schimb sau cedare</h3>
                    <ol class="help-steps compact">
                        <li>Apasa pe <b>propria ta garda</b> de pe OG Linia 1.</li>
                        <li>Alege <b>Schimba</b> si selecteaza cealalta garda L1, sau alege <b>Cedeaza</b> si medicul care o primeste.</li>
                        <li>Verifica rezumatul si confirma.</li>
                    </ol>
                    <h3>Preluare</h3>
                    <ol class="help-steps compact">
                        <li>Apasa pe garda altui medic de pe OG Linia 1.</li>
                        <li>Alege <b>Preia garda</b> si confirma. Nu este necesara aprobarea colegului sau a administratorului.</li>
                    </ol>
                </section>
                <figure class="help-visual action-visual">
                    <figcaption>Imagine explicativa: optiunile depind de garda pe care ai apasat.</figcaption>
                    <div><small>GARDA TA · OG L1</small><b>15 septembrie · CUCU Andrei</b><span>Schimba</span><span>Cedeaza</span></div>
                    <div><small>GARDA ALTUI MEDIC · OG L1</small><b>18 septembrie · MAIER Traian</b><span class="takeover">Preia garda</span></div>
                </figure>
            </div>
        </details>

        <details class="help-chapter">
            <summary><span class="help-number">04</span><span><strong>Garzi: statistici, jurnal si export</strong><small>Istoricul medicului selectat si descarcarea programului</small></span></summary>
            <div class="help-chapter-body">
                <ol class="help-steps">
                    <li>Selecteaza un medic din <b>Personal</b>, indiferent cine este autentificat.</li>
                    <li>Deschide <b>Statistici</b> pentru numarul si distributia garzilor din luna afisata.</li>
                    <li>Deschide <b>Jurnal</b> pentru garzi initiale, preluate, cedate si schimbate.</li>
                    <li>Pentru descarcare, apasa <b>Export</b>, alege luna si specialitatile, apoi descarca raportul PDF.</li>
                </ol>
            </div>
        </details>
        <?php endif; ?>

        <?php if ($helpCanSeeProgOp): ?>
        <details class="help-chapter" id="help-operations">
            <summary><span class="help-number">05</span><span><strong>Program operator: adaugarea unei operatii</strong><small>Data, medic, pacienta si tipul operatiei</small></span></summary>
            <div class="help-chapter-body">
                <?php if (!$helpCanEditProgOp): ?><p class="help-permission-note">Contul tau poate vizualiza si tipari programul. Butoanele de adaugare sau modificare apar numai conturilor care au aceste drepturi.</p><?php endif; ?>
                <ol class="help-steps">
                    <li>Deschide <b>Program operator</b> si apasa <b>Adauga programare</b>.</li>
                    <li>Alege <b>data</b>. Medicul autentificat este selectat implicit, dar poti alege alt medic din lista daca ai dreptul necesar.</li>
                    <li>Completeaza <b>numele pacientei</b> si alege <b>tipul operatiei</b>.</li>
                    <li>Apasa <b>Salveaza programarea</b>. Momentul salvarii stabileste prioritatea in lista asistentei.</li>
                    <li>Programarile introduse pentru saptamana curenta sunt acceptate, dar apar separat, cu rosu, ca <b>Operatie EXTRA</b>.</li>
                </ol>
                <figure class="help-visual operation-visual">
                    <figcaption>Imagine explicativa: campurile obligatorii din formular.</figcaption>
                    <div class="help-form-title">Adauga programare</div>
                    <label><span>Data</span><b>18.09.2026</b></label><label><span>Medic</span><b>CUCU Andrei</b></label>
                    <label><span>Pacienta</span><b>POPESCU MARIA</b></label><label><span>Tip operatie</span><b>Cezariana</b></label>
                    <button type="button" tabindex="-1">Salveaza programarea</button>
                </figure>
            </div>
        </details>

        <details class="help-chapter">
            <summary><span class="help-number">06</span><span><strong>Program operator: modificare, stergere si sali</strong><small>Ce poate face medicul si ce poate face asistenta</small></span></summary>
            <div class="help-chapter-body help-two-column">
                <section><h3>Pentru medic</h3><ol class="help-steps compact"><li>Gaseste programarea in lista cronologica sau in sala.</li><li>Apasa <b>Editeaza</b> pentru a schimba datele permise.</li><li>Apasa <b>Sterge</b> si confirma pentru anulare. Operatiile tale sunt marcate cu un fundal distinct.</li></ol></section>
                <section><h3>Pentru asistenta desemnata</h3><ol class="help-steps compact"><li>Citeste lista <b>De repartizat pe sali</b> de sus in jos; prima programare salvata are prioritate.</li><li>Alege <b>Sala 1, 2, 3 sau 4</b> pentru fiecare programare.</li><li>Foloseste <b>Muta / Reprogrameaza</b> pentru schimbarea salii sau datei si retrimite in lista daca este necesar.</li></ol></section>
                <p class="help-warning full">Nu reincarca pagina in timp ce se salveaza o modificare. Asteapta confirmarea afisata de aplicatie.</p>
            </div>
        </details>

        <details class="help-chapter">
            <summary><span class="help-number">07</span><span><strong>Calendar, tiparire si export</strong><small>Zile de garda, overview si programul de print</small></span></summary>
            <div class="help-chapter-body">
                <ol class="help-steps">
                    <li>In calendar, fiecare zi arata <b>total operatii / operatiile tale</b>. Zilele tale de garda sunt evidentiate prin culoare.</li>
                    <li>Apasa pe o zi pentru programul complet al celor patru sali.</li>
                    <li>Foloseste <b>Overview</b> pentru progresul interventiilor si medicii de garda. La deschidere, Overview afiseaza intotdeauna ziua curenta.</li>
                    <li>Daca apare o urgenta astazi si contul tau poate adauga operatii, apasa <b>Adauga urgenta</b> in sala dorita, completeaza pacienta, medicul si tipul operatiei, apoi confirma. Operatia este marcata automat EXTRA si actiunea este jurnalizata.</li>
                    <li>Foloseste <b>Export</b> pentru raportul PDF. Varianta de tiparire este landscape, cu cate o coloana pentru fiecare sala.</li>
                </ol>
            </div>
        </details>
        <?php endif; ?>

        <details class="help-chapter" id="help-account">
            <summary><span class="help-number">08</span><span><strong>Notificari, parola si siguranta contului</strong><small>Activitatea care te priveste</small></span></summary>
            <div class="help-chapter-body">
                <ol class="help-steps">
                    <li>Apasa <b>Notificari</b> din dreapta sus pentru a vedea cine te-a pus de garda, schimburile, preluarile si alte modificari care te privesc.</li>
                    <li>Apasa <b>Marcheaza toate ca citite</b> dupa ce le-ai verificat.</li>
                    <li>Pentru parola, apasa <b>Schimba parola</b>, introdu parola actuala si de doua ori parola noua.</li>
                    <li>Nu comunica parola altor persoane si foloseste intotdeauna propriul cont: modificarile sunt inregistrate in jurnal.</li>
                    <li>La final, foloseste <b>Iesire</b>, mai ales pe un calculator comun.</li>
                </ol>
            </div>
        </details>

        <details class="help-chapter" id="help-errors">
            <summary><span class="help-number">09</span><span><strong>Probleme frecvente</strong><small>Ce verifici inainte sa ceri ajutor</small></span></summary>
            <div class="help-chapter-body">
                <dl class="help-faq">
                    <div><dt>Nu vad un medic in lista de garzi.</dt><dd>Medicul poate fi marcat „nu participa la garzi” sau poate apartine altei specialitati. Verifica specialitatea selectata.</dd></div>
                    <div><dt>Nu pot modifica OG Linia 1.</dt><dd>Este normal pentru conturile medicale obisnuite. Apasa pe propria garda pentru schimb/cedare sau pe garda altui medic pentru preluare.</dd></div>
                    <div><dt>Primesc eroare la adaugarea unei garzi.</dt><dd>Aplicatia nu permite acelasi medic in doua zile consecutive, indiferent de linie.</dd></div>
                    <div><dt>Nu vad butonul de adaugare sau stergere.</dt><dd>Contul nu are dreptul respectiv sau incerci o actiune protejata. Cere administratorului verificarea drepturilor.</dd></div>
                    <div><dt>Operatia apare cu rosu si nu este intr-o sala.</dt><dd>Este o programare din saptamana curenta si a fost marcata automat ca operatie EXTRA.</dd></div>
                    <div><dt>Datele nu par actualizate.</dt><dd>Apasa Reincarca o singura data. Daca problema continua, noteaza pagina, data si mesajul afisat pentru administrator.</dd></div>
                </dl>
            </div>
        </details>
    </div>
</section>
