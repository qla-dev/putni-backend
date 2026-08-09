<!doctype html>
<html lang="bs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page === 'privacy' ? 'Politika privatnosti' : ($page === 'terms' ? 'Uslovi korištenja' : 'Kolačići') }} · Putni nalozi</title>
    <style>
        :root { color-scheme: light; --blue:#007aff; --text:#172033; --muted:#637083; --line:#e4e8ef; --surface:#fff; --bg:#f5f7fb; }
        * { box-sizing:border-box; } body { margin:0; font-family:Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:var(--bg); color:var(--text); }
        main { max-width:780px; margin:0 auto; padding:48px 20px 64px; } .brand { color:var(--blue); font-weight:800; text-decoration:none; font-size:15px; } h1 { font-size:34px; letter-spacing:-.7px; margin:28px 0 8px; } .updated { color:var(--muted); margin:0 0 32px; font-size:14px; }
        article { background:var(--surface); border:1px solid var(--line); border-radius:20px; padding:28px; box-shadow:0 8px 28px rgba(22,31,48,.05); } h2 { font-size:18px; margin:26px 0 8px; } h2:first-child { margin-top:0; } p, li { line-height:1.65; color:#344054; } ul { padding-left:22px; } footer { display:flex; flex-wrap:wrap; gap:14px; margin-top:22px; font-size:14px; } footer a { color:var(--blue); text-decoration:none; }
    </style>
</head>
<body>
<main>
    <a class="brand" href="/">qla.dev Business · Putni nalozi</a>
    @if ($page === 'privacy')
        <h1>Politika privatnosti</h1><p class="updated">Posljednje ažuriranje: 9. august 2026.</p>
        <article>
            <h2>Podaci koje obrađujemo</h2><p>Aplikacija obrađuje podatke računa i putnih naloga koje unesete: ime i kontakt podatke, podatke poslodavca, rute, datume putovanja, troškove, slike računa i podatke potrebne za prijavu.</p>
            <h2>Zašto ih koristimo</h2><p>Podaci se koriste za kreiranje, obračun, dijeljenje i izvoz putnih naloga, održavanje korisničkog računa, sigurnost usluge i korisničku podršku. Slike računa mogu se poslati AI servisu samo radi očitavanja podataka koje zatražite.</p>
            <h2>Čuvanje i dijeljenje</h2><p>Podatke čuvamo samo koliko je potrebno za rad aplikacije, zakonske obaveze ili legitimnu zaštitu usluge. Ne prodajemo lične podatke. Podaci se dijele samo sa pružaocima infrastrukture i obrade potrebnima za rad usluge ili kada to nalaže zakon.</p>
            <h2>Vaša prava</h2><p>Možete zatražiti pristup, ispravku, brisanje ili ograničenje obrade svojih podataka, kada je to primjenjivo. Za zahtjev koristite kanal podrške koji vam je dostupan u aplikaciji ili kod vaše organizacije.</p>
            <h2>Sigurnost</h2><p>Primjenjujemo razumne tehničke i organizacione mjere za zaštitu podataka. Nijedna internet usluga ne može garantovati apsolutnu sigurnost.</p>
        </article>
    @elseif ($page === 'terms')
        <h1>Uslovi korištenja</h1><p class="updated">Posljednje ažuriranje: 9. august 2026.</p>
        <article>
            <h2>Namjena usluge</h2><p>Putni nalozi je poslovni alat za pripremu, evidenciju i obračun putnih naloga i troškova. Korisnik je odgovoran za tačnost unesenih podataka i za pribavljanje potrebnih odobrenja unutar svoje organizacije.</p>
            <h2>AI očitavanje</h2><p>AI očitavanje je pomoćna funkcija. Rezultate skeniranja, iznose, datume, kategorije i obračune uvijek pregledajte prije slanja ili izvoza. Aplikacija ne zamjenjuje računovodstvenu, poresku ni pravnu provjeru.</p>
            <h2>Korisnički račun</h2><p>Čuvajte pristupne podatke i ne dijelite račun sa neovlaštenim osobama. Organizacija i korisnik odgovorni su za odobravanje pristupa putnim nalozima i vozilima.</p>
            <h2>Dostupnost</h2><p>Uslugu možemo mijenjati, održavati ili privremeno ograničiti radi sigurnosti i unapređenja. U najvećoj mjeri dopuštenoj zakonom ne odgovaramo za indirektnu štetu nastalu korištenjem usluge.</p>
        </article>
    @else
        <h1>Politika kolačića</h1><p class="updated">Posljednje ažuriranje: 9. august 2026.</p>
        <article>
            <h2>Neophodni kolačići</h2><p>Koristimo neophodne kolačiće i slične tehnologije za sigurnost, održavanje sesije i osnovni rad web stranice. Bez njih prijava i zaštita usluge ne mogu pravilno funkcionisati.</p>
            <h2>Opcionalni kolačići</h2><p>Trenutno ne koristimo kolačiće za oglašavanje. Ako u budućnosti uvedemo analitičke ili druge opcionalne kolačiće, zatražit ćemo odgovarajući pristanak prije njihovog postavljanja.</p>
            <h2>Upravljanje kolačićima</h2><p>Kolačiće možete obrisati ili blokirati u postavkama preglednika. Blokiranje neophodnih kolačića može uticati na rad web stranice.</p>
        </article>
    @endif
    <footer><a href="/putni-nalozi/privacy">Privatnost</a><a href="/putni-nalozi/terms">Uslovi</a><a href="/putni-nalozi/cookies">Kolačići</a><a href="/putni-nalozi/help">Pravilnik i pomoć</a></footer>
</main>
</body>
</html>
