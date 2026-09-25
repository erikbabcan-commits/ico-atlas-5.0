<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <title>Due Diligence report — {{ $company->ico }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; margin: 0; }
        h1 { font-size: 20px; margin: 0 0 2px 0; color: #0f172a; }
        h2 { font-size: 13px; margin: 18px 0 6px 0; color: #0f172a; border-bottom: 2px solid #0f172a; padding-bottom: 3px; }
        .muted { color: #64748b; font-size: 9px; }
        .head { margin-bottom: 14px; }
        .score-badge { display: inline-block; padding: 4px 10px; border-radius: 4px; font-weight: bold; font-size: 12px; color: #fff; }
        .score-low { background: #16a34a; }
        .score-mid { background: #d97706; }
        .score-high { background: #dc2626; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th { text-align: left; background: #f1f5f9; padding: 4px 6px; border: 1px solid #cbd5e1; font-size: 9px; }
        td { padding: 4px 6px; border: 1px solid #cbd5e1; font-size: 9px; vertical-align: top; }
        .flag { padding: 3px 6px; border: 1px solid #cbd5e1; border-radius: 3px; margin: 2px 2px 0 0; display: inline-block; font-size: 9px; }
        .disclaimer { margin-top: 22px; padding: 8px; background: #f8fafc; border-left: 3px solid #64748b; font-size: 8px; color: #334155; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8px; color: #94a3b8; }
        .kv { width: 100%; }
        .kv td { border: none; padding: 2px 0; }
        .kv td:first-child { width: 180px; color: #64748b; }
    </style>
</head>
<body>

<div class="head">
    <h1>WhoIsWho SK — Due Diligence report</h1>
    <div class="muted">Generované {{ $generatedAt }} | Zdroje: RPO, RÚZ, RPVS (verejné registre SR)</div>
</div>

<h2>1. Identifikácia subjektu</h2>
<table class="kv">
    <tr><td>Názov</td><td>{{ $company->name }}</td></tr>
    <tr><td>IČO</td><td>{{ $company->ico }}</td></tr>
    @if($company->dic)<tr><td>DIČ</td><td>{{ $company->dic }}</td></tr>@endif
    <tr><td>Právna forma</td><td>{{ $company->legal_form }}{{ $company->legal_form_code ? ' (kód ' . $company->legal_form_code . ')' : '' }}</td></tr>
    <tr><td>Stav</td><td>{{ $company->status }}</td></tr>
    @if($company->street)<tr><td>Sídlo</td><td>{{ $company->street }}{{ $company->municipality ? ', ' . $company->municipality : '' }}{{ $company->postal_code ? ' ' . $company->postal_code : '' }}</td></tr>@endif
    @if($company->established_on)<tr><td>Vznik</td><td>{{ $company->established_on?->format('d.m.Y') }}</td></tr>@endif
</table>

<h2>2. Rizikové hodnotenie</h2>
<p>
    <span class="score-badge {{ $risk['score'] < 0.35 ? 'score-low' : ($risk['score'] < 0.7 ? 'score-mid' : 'score-high') }}">
        Skóre: {{ round($risk['score'] * 100) }}/100
    </span>
</p>
@if(count($risk['flags']) === 0)
    <p>Zistené rizikové indikátory: <strong>žiadne</strong>.</p>
@else
    <p>Zistené rizikové indikátory:</p>
    @foreach($risk['flags'] as $flag)
        <span class="flag">{{ $flag['code'] }}{{ !empty($flag['detail']) ? ' — ' . $flag['detail'] : '' }}</span>
    @endforeach
@endif
<table>
    <tr><th>Zdroje hodnotenia</th></tr>
    @foreach($risk['sources'] as $src)
        <tr><td>{{ strtoupper($src) }}</td></tr>
    @endforeach
</table>

<h2>3. Štatutárne orgány</h2>
@if(count($statutory) === 0)
    <p class="muted">Žiadne záznamy.</p>
@else
<table>
    <tr><th>Meno</th><th>Funkcia</th><th>Od</th><th>Zdroj</th></tr>
    @foreach($statutory as $s)
    <tr>
        <td>{{ $s['name'] }}</td>
        <td>{{ $s['role'] }}</td>
        <td>{{ $s['valid_from'] ?: '—' }}</td>
        <td>{{ strtoupper($s['source']) }}</td>
    </tr>
    @endforeach
</table>
@endif

<h2>4. Vlastnícka štruktúra</h2>
@if(count($shareholders) === 0)
    <p class="muted">Žiadne záznamy.</p>
@else
<table>
    <tr><th>Subjekt</th><th>Typ</th><th>Od</th><th>Zdroj</th></tr>
    @foreach($shareholders as $s)
    <tr>
        <td>{{ $s['name'] }}</td>
        <td>{{ $s['kind'] === 'PO' ? 'Právnická osoba' : 'Fyzická osoba' }}</td>
        <td>{{ $s['valid_from'] ?: '—' }}</td>
        <td>{{ strtoupper($s['source']) }}</td>
    </tr>
    @endforeach
</table>
@endif

<h2>5. Graf prepojení (depth 1)</h2>
<table class="kv">
    <tr><td>Počet uzlov</td><td>{{ $graph['counts']['nodes'] }}</td></tr>
    <tr><td>Počet prepojení</td><td>{{ $graph['counts']['edges'] }}</td></tr>
</table>

<div class="disclaimer">
    <strong>Disclaimer:</strong> {{ $disclaimer }}
    Tento report je automaticky generovaný výstup z verejných databáz. Nie je úradným výpisom ani právnym posudkom.
</div>

</body>
</html>
