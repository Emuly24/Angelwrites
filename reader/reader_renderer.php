<?php
// ============================================================
//  READER_RENDERER.PHP – HTML/PDF/EPUB rendering engine
// ============================================================

function renderProcessedContent($content_html, $toc) {
    $html = '<div id="awReaderText" class="aw-reader-text">';
    $html .= $content_html;
    $html .= '</div>';

    // Add TOC if available
    if (!empty($toc)) {
        $html .= '<div class="aw-reader-toc">';
        $html .= '<h3>Table of Contents</h3>';
        $html .= '<ul>';
        foreach ($toc as $index => $item) {
            $html .= '<li><a href="#" class="aw-toc-link" data-chapter="' . $index . '">' . htmlspecialchars($item['title']) . '</a></li>';
        }
        $html .= '</ul>';
        $html .= '</div>';
    }

    return $html;
}

function renderPdfFallback($file_path) {
    $html = '<div class="aw-reader-fallback" id="awPdfContainer">';
    $html .= '<canvas id="awPdfCanvas"></canvas>';
    $html .= '<div class="aw-pdf-controls">';
    $html .= '<button id="awPdfPrev"><i class="fas fa-chevron-left"></i></button>';
    $html .= '<span id="awPdfPageInfo">1 / 1</span>';
    $html .= '<button id="awPdfNext"><i class="fas fa-chevron-right"></i></button>';
    $html .= '<input type="range" id="awPdfZoom" min="0.5" max="2" step="0.1" value="1">';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

function renderEpubFallback($file_path) {
    $html = '<div class="aw-reader-fallback" id="awEpubContainer">';
    $html .= '<div id="awEpubViewer"></div>';
    $html .= '<div class="aw-epub-controls">';
    $html .= '<button id="awEpubPrev"><i class="fas fa-chevron-left"></i></button>';
    $html .= '<span id="awEpubPageInfo">1 / 1</span>';
    $html .= '<button id="awEpubNext"><i class="fas fa-chevron-right"></i></button>';
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

function renderUnsupported() {
    $html = '<div class="aw-reader-unsupported">';
    $html .= '<i class="fas fa-exclamation-triangle"></i>';
    $html .= '<p>This book format is not supported for online reading.</p>';
    $html .= '</div>';
    return $html;
}