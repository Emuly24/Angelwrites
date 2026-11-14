<?php
/**
 * The one book card: the store (books.php), the home page and anywhere else a book is listed.
 * Cover first, like a bookshelf: the cover, the title, the author, a two-line blurb and one clear
 * action. Styles live in assets/css/aw-books.css, loaded with aw_book_assets().
 *
 * aw_book_card($book, $opts) returns HTML and never throws.
 *   $book  a books row; needs id and title; uses author, cover_path, description, is_free, price,
 *          sale_price and the sale dates when present.
 *   $opts  'author'   => author name to show (default $book['author'])
 *          'readable' => true when the reader has pages for this book (shows "Read")
 *          'owned'    => true when the member owns it
 *          'buy'      => true to show the Download / Buy button (books.php has the order form)
 *          'heading'  => 'h3' (default) or 'h2'
 */
require_once __DIR__ . '/commerce.php';

function aw_book_assets() {
    return '<link rel="stylesheet" href="' . SITE_URL . '/assets/css/aw-books.css?v=1">';
}

function aw_book_cover_url(array $book) {
    $c = trim((string)($book['cover_path'] ?? ''));
    if ($c === '') return '';
    if (preg_match('#^https?://#i', $c)) return $c;
    if (function_exists('get_image_url')) return get_image_url($c);
    return rtrim(SITE_URL, '/') . '/' . ltrim($c, '/');
}

function aw_book_card(array $book, array $opts = []) {
    try {
        $id = (int)($book['id'] ?? 0);
        $h = ($opts['heading'] ?? 'h3') === 'h2' ? 'h2' : 'h3';
        $title = htmlspecialchars((string)($book['title'] ?? 'Book'));
        $author = trim((string)($opts['author'] ?? ($book['author'] ?? '')));
        $url = htmlspecialchars(SITE_URL . '/book.php?id=' . $id);
        $cover = aw_book_cover_url($book);
        $free = !empty($book['is_free']);
        $owned = !empty($opts['owned']);
        $price = (float)($book['price'] ?? 0);
        $sale = !$free && aw_book_on_sale($book);
        $blurb = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($book['description'] ?? ''))));

        $flag = $owned ? 'Owned' : ($sale ? 'Sale' : ''); // "Free" already shows as the price

        $html = '<article class="bk-card" id="book-' . $id . '">';
        // The cover repeats the title link, so it is hidden from screen readers and the tab order.
        $html .= '<a class="bk-cover" href="' . $url . '" tabindex="-1" aria-hidden="true">';
        if ($cover !== '') $html .= '<img src="' . htmlspecialchars($cover) . '" alt="" loading="lazy" onerror="this.remove()">';
        $html .= '<span class="bk-cover-ph"><span>' . $title . '</span>' . ($author !== '' ? '<small>' . htmlspecialchars($author) . '</small>' : '') . '</span>';
        if ($flag !== '') $html .= '<span class="bk-flag bk-flag-' . strtolower($flag) . '">' . $flag . '</span>';
        $html .= '</a>';

        $html .= '<div class="bk-body">';
        $html .= '<' . $h . ' class="bk-title"><a href="' . $url . '">' . $title . '</a></' . $h . '>';
        if ($author !== '') $html .= '<p class="bk-author">' . htmlspecialchars($author) . '</p>';
        if ($blurb !== '') $html .= '<p class="bk-blurb">' . htmlspecialchars($blurb) . '</p>';

        // Price
        if ($owned) $p = '<span class="bk-price-own">In your library</span>';
        elseif ($free) $p = '<span class="bk-price-free">Free</span>';
        elseif ($price <= 0) $p = '<span class="bk-price-soon">Not yet on sale</span>';
        elseif ($sale) $p = '<s>' . aw_mwk($book['price']) . '</s> <span class="bk-price-sale">' . aw_mwk($book['sale_price']) . '</span>';
        else $p = '<span>' . aw_mwk($book['price']) . '</span>';

        $html .= '<div class="bk-foot"><span class="bk-price">' . $p . '</span><span class="bk-actions">';
        if (!empty($opts['readable'])) {
            $html .= '<a class="bk-btn bk-btn-primary" href="' . htmlspecialchars(SITE_URL . '/reader/reader.php?id=' . $id) . '">Read</a>';
        } else {
            $html .= '<a class="bk-btn" href="' . $url . '" aria-label="View ' . $title . '">View</a>';
        }
        if (!empty($opts['buy'])) {
            if (function_exists('isLoggedIn') && isLoggedIn()) {
                if ($free || $owned) {
                    $html .= '<button type="button" class="bk-btn bk-btn-icon download-btn" data-id="' . $id . '" data-free="1" data-price="0" aria-label="Download ' . $title . '" title="Download"><i class="fas fa-arrow-down" aria-hidden="true"></i></button>';
                } elseif ($price > 0) {
                    $html .= '<button type="button" class="bk-btn bk-btn-buy download-btn" data-id="' . $id . '" data-free="0" data-price="' . htmlspecialchars((string)aw_book_price($book)) . '">Buy</button>';
                }
            } elseif ($free || $price > 0) {
                $back = urlencode('/books.php#book-' . $id);
                $html .= '<a class="bk-btn bk-btn-icon" href="' . htmlspecialchars(SITE_URL . '/login.php?redirect=' . $back) . '" aria-label="Log in to ' . ($free ? 'download' : 'buy') . ' ' . $title . '" title="Log in to ' . ($free ? 'download' : 'buy') . '"><i class="fas fa-arrow-' . ($free ? 'down' : 'right-to-bracket') . '" aria-hidden="true"></i></a>';
            }
        }
        $html .= '</span></div></div></article>';
        return $html;
    } catch (Throwable $e) {
        error_log('aw_book_card: ' . $e->getMessage());
        return '';
    }
}
