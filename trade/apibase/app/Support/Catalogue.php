<?php

namespace App\Support;

use App\Models\Business\Page;
use App\Models\Business\Product;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * A page as a PDF: the thing a supplier sends to a buyer.
 *
 * A cover sheet with the company, then the products, then how to reach
 * them — every sheet carrying the GrapOut Trade mark and the link back to
 * the page, so the file also brings people in. Prices can be left off:
 * a catalogue without prices invites the enquiry that opens the talk.
 */
class Catalogue
{
    public const MAX_PRODUCTS = 60;

    public static function pdf(Page $page, string $link, bool $prices = true): string
    {
        return Pdf::loadHTML(self::html($page, $link, $prices))->setPaper('a4')->output();
    }

    public static function html(Page $page, string $link, bool $prices = true): string
    {
        $page->loadMissing(['owner', 'products.images', 'tradeLines']);
        $products = $page->products->where('status', 'active')->sortBy('sort')->take(self::MAX_PRODUCTS);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $logo = self::img($page->logo_path);
        $cover = self::img($page->cover_path) ?: self::img($page->hero_path);
        $kind = ucfirst(str_replace('_', ' ', (string) $page->kind));
        $where = implode(', ', array_filter([$page->city, $page->country]));
        $verified = $page->verification_status === 'verified' ? '<span class="badge">&#10004; Verified on GrapOut Trade</span>' : '';
        $count = $products->count();
        $date = now()->format('j F Y');
        $frontDoor = preg_replace('#/c/.*$#', '', $link) ?: $link;

        // What the company sells and buys, from its trade lines.
        $sells = $page->tradeLines->where('direction', 'sell')->pluck('description')->filter()->take(8)->map($e)->implode(' &middot; ');
        $buys = $page->tradeLines->where('direction', 'buy')->pluck('description')->filter()->take(8)->map($e)->implode(' &middot; ');
        $markets = $page->markets ? implode(', ', array_map($e, $page->markets)) : '';
        $certs = $page->certifications ? implode(', ', array_map($e, $page->certifications)) : '';
        $facts = '';
        foreach (array_filter([
            'Company type' => $kind,
            'Based in' => $where,
            'Established' => $page->year_established,
            'Sells' => $sells,
            'Buys' => $buys,
            'Markets served' => $markets,
            'Certifications' => $certs,
        ]) as $k => $v) {
            $facts .= '<tr><td class="k">' . $e($k) . '</td><td>' . (in_array($k, ['Sells', 'Buys'], true) ? $v : $e($v)) . '</td></tr>';
        }

        $contacts = array_filter([
            $page->website ? ['Website', $e($page->website)] : null,
            $page->contactsPublic() && $page->email ? ['Email', $e($page->email)] : null,
            $page->contactsPublic() && $page->phone ? ['Phone', $e($page->phone)] : null,
            $page->address ? ['Address', $e(implode(', ', array_filter([$page->address, $page->city, $page->country])))] : null,
        ]);
        $contactRows = '';
        foreach ($contacts as [$k, $v]) {
            $contactRows .= '<tr><td class="k">' . $k . '</td><td>' . $v . '</td></tr>';
        }
        if ($page->owner) {
            $contactRows .= '<tr><td class="k">Runs the page</td><td>' . $e($page->owner->name) . '</td></tr>';
        }
        $contactRows .= '<tr><td class="k">On GrapOut Trade</td><td><a href="' . $e($link) . '">' . $e($link) . '</a></td></tr>';

        // A different soft colour behind every product, so a page of them reads as a shelf, not a list.
        $tints = [['#fff7ed', '#fdba74'], ['#eff6ff', '#93c5fd'], ['#f0fdf4', '#86efac'], ['#fdf4ff', '#d8b4fe'], ['#fefce8', '#fde047'], ['#f0fdfa', '#5eead4'], ['#fff1f2', '#fda4af'], ['#f5f3ff', '#c4b5fd']];
        $cards = '';
        foreach ($products->values() as $i => $p) {
            [$bg, $edge] = $tints[$i % count($tints)];
            $pic = $p->cover();
            $img = $pic ? self::img($pic->displayPath()) : null;
            $bits = array_filter([
                $p->moq !== null ? 'MOQ ' . number_format((float) $p->moq, 2) . ' ' . $e($p->moq_unit) : null,
                $p->terms ? $e(implode(' / ', $p->terms)) : null,
                $p->hs_code ? 'HS ' . $e($p->hs_code) : null,
                $p->category ? $e($p->category) : null,
            ]);
            $specs = '';
            foreach (array_slice($p->specifications ?? [], 0, 6) as $s) {
                $specs .= '<tr><td class="k">' . $e($s['label'] ?? '') . '</td><td>' . $e($s['value'] ?? '') . '</td></tr>';
            }
            $priceLine = $prices
                ? '<p class="price">' . $e(self::price($p)) . '</p>'
                : '<p class="price ask">Price on enquiry</p>';
            $cards .= "<div class=\"card\" style=\"border-color:{$edge}\">"
                . "<table class=\"cover\" style=\"background:{$bg}\"><tr><td align=\"center\" valign=\"middle\">" . ($img ? "<img src=\"{$img}\">" : '<span class="initial" style="color:' . $edge . '">' . $e(mb_substr($p->name, 0, 1)) . '</span>') . '</td></tr></table>'
                . "<div class=\"body\" style=\"border-top:3px solid {$edge}\"><h3>" . $e($p->name) . '</h3>'
                . $priceLine
                . ($bits ? '<p class="facts">' . implode(' &middot; ', $bits) . '</p>' : '')
                . ($p->summary ? '<p class="summary">' . $e($p->summary) . '</p>' : '')
                . ($specs ? "<table class=\"specs\">{$specs}</table>" : '')
                . '</div></div>';
        }

        $about = $page->about ? '<div class="about"><h2>About ' . $e($page->name) . '</h2><p>' . nl2br($e($page->about)) . '</p></div>' : '';
        $coverBlock = $cover ? "<div class=\"hero\"><img src=\"{$cover}\"></div>" : '<div class="hero plain"></div>';
        $logoHtml = $logo ? "<img class=\"logo\" src=\"{$logo}\">" : '';
        $tagHtml = $page->tagline ? '<p class="tag">' . $e($page->tagline) . '</p>' : '';
        $meta = $e(trim($kind . ($where ? ' · ' . $where : '')));
        $pricesNote = $prices ? '' : '<p class="note">Prices are shared on enquiry. Ask on the page and the reply comes to your email and your GrapOut Trade account.</p>';
        $name = $e($page->name);
        $linkE = $e($link);
        $doorE = $e($frontDoor);
        $followers = (int) $page->followers_count;

        return <<<HTML
        <!doctype html><html><head><meta charset="utf-8"><style>
          @page { margin: 16mm 14mm 22mm 14mm; }
          body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #0f172a; font-size: 10.5px; line-height: 1.35; }
          a { color: #b45309; text-decoration: none; }
          .brand { position: fixed; top: -10mm; left: 0; right: 0; font-size: 9px; color: #64748b; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
          .brand b { color: #b45309; letter-spacing: 1px; text-transform: uppercase; }
          .brand span.r { float: right; }
          .foot { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 9px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 5px; }
          .foot b { color: #b45309; }
          .hero { height: 62mm; border-radius: 10px; overflow: hidden; background: #0f1423; text-align: center; }
          .hero img { width: 100%; height: 62mm; }
          .cover-sheet { page-break-after: always; }
          .idcard { margin: 10px 0 0; background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 12px 16px; }
          .idcard .logo { width: 70px; height: 70px; object-fit: cover; border-radius: 12px; float: left; margin-right: 14px; border: 1px solid #e2e8f0; }
          .idcard h1 { font-size: 24px; margin: 4px 0 2px; }
          .idcard .tag { margin: 0; color: #334155; font-size: 12.5px; }
          .idcard .meta { margin: 4px 0 0; color: #64748b; font-size: 10px; }
          .badge { font-size: 9px; color: #0369a1; background: #e0f2fe; border-radius: 10px; padding: 2px 7px; vertical-align: middle; margin-left: 6px; }
          .two { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 14px; }
          .two > tbody > tr > td { vertical-align: top; width: 50%; padding: 0 6px; }
          .box { border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; }
          .box h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #b45309; margin: 0 0 6px; }
          .kv { border-collapse: collapse; width: 100%; font-size: 10px; }
          .kv td { padding: 3px 0; border-top: 1px solid #f1f5f9; vertical-align: top; }
          .kv td.k { color: #64748b; width: 34%; }
          .cta { margin: 14px 8mm 0; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 12px 14px; }
          .cta h2 { margin: 0 0 4px; font-size: 13px; }
          .cta p { margin: 2px 0; font-size: 10.5px; color: #334155; }
          .cta .link { font-family: DejaVu Sans Mono, monospace; font-size: 10px; color: #b45309; word-break: break-all; }
          .note { color: #92400e; font-size: 10px; margin: 8px 0 0; }
          .about { margin: 14px 8mm 0; }
          .about h2 { font-size: 12px; margin: 0 0 4px; }
          .about p { margin: 0; color: #334155; }
          .section { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #b45309; margin: 0 0 8px; }
          .card { width: 47%; display: inline-block; vertical-align: top; margin: 0 1.5% 10px; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; page-break-inside: avoid; }
          .cover { width: 100%; height: 150px; border-collapse: collapse; }
          .cover td { text-align: center; vertical-align: middle; height: 150px; padding: 0; }
          .cover img { max-width: 100%; max-height: 146px; }
          .cover .initial { font-size: 42px; font-weight: bold; }
          .body { padding: 8px 10px 10px; }
          h3 { margin: 0 0 3px; font-size: 12.5px; }
          .price { margin: 0; color: #b45309; font-weight: bold; font-size: 12px; }
          .price.ask { color: #475569; font-weight: normal; font-style: italic; }
          .facts { margin: 3px 0 0; color: #475569; font-size: 9.5px; }
          .summary { margin: 4px 0 0; color: #334155; }
          .specs { margin-top: 5px; border-collapse: collapse; width: 100%; font-size: 9px; }
          .specs td { padding: 1.5px 0; border-top: 1px solid #f1f5f9; }
          .specs td.k { color: #64748b; width: 38%; }
        </style></head><body>
          <div class="brand"><b>GrapOut Trade</b> &nbsp; buyers and suppliers, one network <span class="r">{$name} &middot; catalogue &middot; {$date}</span></div>
          <div class="foot">Enquire, follow and message <b>{$name}</b> at <a href="{$linkE}">{$linkE}</a> &nbsp;&middot;&nbsp; More suppliers and buyers at <a href="{$doorE}">{$doorE}</a></div>

          <div class="cover-sheet">
            {$coverBlock}
            <div class="idcard">
              {$logoHtml}
              <h1>{$name}{$verified}</h1>
              {$tagHtml}
              <p class="meta">{$meta} &nbsp;&middot;&nbsp; {$count} products &nbsp;&middot;&nbsp; {$followers} followers on GrapOut Trade</p>
              <div style="clear:both"></div>
            </div>
            <table class="two"><tbody><tr>
              <td><div class="box"><h2>Company</h2><table class="kv">{$facts}</table></div></td>
              <td><div class="box"><h2>Reach us</h2><table class="kv">{$contactRows}</table></div></td>
            </tr></tbody></table>
            <div class="cta">
              <h2>Enquire in one step</h2>
              <p>Open the page, press <b>Send enquiry</b>, and your question reaches {$name} at once. The reply comes to your email and to your GrapOut Trade account, where you can follow the company, compare quotes and open a chat or a video meeting.</p>
              <p class="link">{$linkE}</p>
              {$pricesNote}
            </div>
            {$about}
          </div>

          <p class="section">Products &middot; {$count}</p>
          <div class="grid">{$cards}</div>
        </body></html>
        HTML;
    }

    public static function priceLabel(Product $p): string
    {
        return self::price($p);
    }

    private static function price(Product $p): string
    {
        $unit = $p->price_unit ? " / {$p->price_unit}" : '';
        $n = fn ($v) => number_format((float) $v, 2);
        if ($p->price_type === 'on_request' || ($p->price_min === null && $p->price_max === null)) {
            return 'Price on request';
        }
        if ($p->price_type === 'fixed' || $p->price_max === null || $p->price_min == $p->price_max) {
            return "{$p->currency} " . $n($p->price_min) . $unit;
        }

        return "{$p->currency} " . $n($p->price_min) . ' – ' . $n($p->price_max) . $unit;
    }

    /** A stored picture as a data URI, so the PDF carries it. */
    private static function img(?string $path): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }
        $mime = Storage::disk('public')->mimeType($path) ?: 'image/jpeg';

        return 'data:' . $mime . ';base64,' . base64_encode(Storage::disk('public')->get($path));
    }
}
