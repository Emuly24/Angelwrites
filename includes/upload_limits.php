<?php
/**
 * R: every upload limit on the site, in one place.
 *
 * Nothing else in the code base holds a size, type or quota number for uploads; the shared helper
 * (includes/upload.php) reads them from here. Change a number here and every upload path follows.
 *
 * Two things the numbers here cannot override:
 *   1. The host's own PHP limits (upload_max_filesize / post_max_size). A file larger than those never
 *      reaches PHP, so the effective limit is always the smaller of the two. Labels on the forms show
 *      the effective number.
 *   2. Bible databases (assets/bible/*.db) and the site's logo/brand assets are not member uploads.
 *      They do not pass through the helper and are EXEMPT from the member quotas below.
 */

if (!function_exists('aw_upload_limits')) {
    function aw_upload_limits(): array
    {
        static $limits = null;
        if ($limits !== null) return $limits;

        $MB = 1024 * 1024;
        $limits = [
            // ---- Images: blog / poem / reflection covers, comment photos, thumbnails ----
            'image' => [
                'label'           => 'image',
                'max_bytes'       => 2 * $MB,     // size of the file that is finally stored
                'input_max_bytes' => 12 * $MB,    // largest original accepted when the server can resize it
                'max_edge'        => 1600,        // long edge in pixels after resizing
                'jpeg_quality'    => 85,
                'prefer_webp'     => false,       // true: store WebP instead of JPEG where GD supports it
                'mimes'           => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'],
                'hint_types'      => 'JPG, PNG or WebP',
            ],
            // ---- Avatars (profile pictures) ----
            'avatar' => [
                'label'           => 'profile picture',
                'max_bytes'       => 500 * 1024,
                'input_max_bytes' => 12 * $MB,
                'crop'            => 400,         // centre-cropped square, 400 x 400
                'jpeg_quality'    => 85,
                'prefer_webp'     => false,
                'mimes'           => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'],
                'hint_types'      => 'JPG, PNG or WebP',
            ],
            // ---- Audio: voice comments, poem / reflection recordings, audio notes ----
            // Opus (WebM/Ogg) and M4A are preferred. MP3 and WAV stay allowed because the poem editor
            // already accepts them.
            'audio' => [
                'label'       => 'audio',
                'max_bytes'   => 5 * $MB,
                'max_seconds' => 180,             // enforced when the length can be read from the file
                'mimes'       => [
                    'audio/webm' => 'webm', 'video/webm' => 'webm',          // finfo reports audio-only WebM as video/webm
                    'audio/ogg' => 'ogg', 'application/ogg' => 'ogg', 'audio/opus' => 'ogg',
                    'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'video/mp4' => 'm4a',   // Safari records audio as MP4
                    'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3',
                    'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav',
                ],
                'extra_exts'  => ['mp4', 'oga', 'opus'],
                'hint_types'  => 'M4A, MP3, Ogg/Opus, WebM or WAV',
            ],
            // ---- Video files chosen from the device: MP4 (H.264) only. No transcoding on this host. ----
            'video' => [
                'label'       => 'video',
                'max_bytes'   => 20 * $MB,
                'max_seconds' => 120,
                'mimes'       => ['video/mp4' => 'mp4', 'video/quicktime' => 'mp4'],
                'require_h264' => true,
                'too_big'     => 'Please compress this video first',
                'hint_types'  => 'MP4 (H.264)',
            ],
            // ---- Video recorded inside the site (the camera recorder). Safari and current Chrome record
            // MP4; Firefox and older Chrome can only record WebM, so WebM is accepted here and nowhere
            // else. Set allow_webm to false to refuse those recordings as well. ----
            'video_recording' => [
                'label'       => 'video recording',
                'max_bytes'   => 20 * $MB,
                'max_seconds' => 120,
                'mimes'       => ['video/mp4' => 'mp4', 'video/quicktime' => 'mp4', 'video/webm' => 'webm'],
                'allow_webm'  => true,
                'require_h264' => true,           // applies to MP4 recordings
                'too_big'     => 'This recording is too long to save here. Please record a shorter one',
                'hint_types'  => 'MP4 or WebM',
            ],
            // ---- Books: PDF or EPUB, plus DOCX because the book processor already reads it.
            // Old .doc files are refused (the processor cannot read them). ----
            'book' => [
                'label'      => 'book',
                'max_bytes'  => 25 * $MB,
                'mimes'      => [
                    'application/pdf' => 'pdf',
                    'application/epub+zip' => 'epub',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                    'application/zip' => 'zip',   // EPUB and DOCX are zip files; the helper looks inside to tell which
                ],
                'hint_types' => 'PDF, EPUB or DOCX',
            ],
        ];

        // ---- Per-member quotas (all types together) ----
        // R14: 100 MB per member in total. On the free host the media pool is about 3 GB, so 500 MB each
        // could not be honoured for more than a handful of members. The two numbers can be raised
        // without touching code, in Admin > Settings > Uploads (stored in the settings table as
        // upload_member_total_mb / upload_member_daily_mb), for the day the site moves to a paid plan.
        $totalMb = 100;
        $dailyMb = 50;
        if (function_exists('aw_setting')) {
            $t = (int)aw_setting('upload_member_total_mb', 0);
            $d = (int)aw_setting('upload_member_daily_mb', 0);
            if ($t >= 10 && $t <= 10000) $totalMb = $t;
            if ($d >= 5 && $d <= 2000) $dailyMb = $d;
        }
        $limits['_quota'] = [
            'per_day_bytes' => min($dailyMb, $totalMb) * $MB,   // resets at midnight, server time
            'total_bytes'   => $totalMb * $MB,                  // everything the member has stored
            'default_total_mb' => 100,
            'default_daily_mb' => 50,
            // Admins publish the site's books and videos, so the member quotas do not apply to them.
            // Their uploads are still counted, so the totals stay visible.
            'exempt_roles'  => ['admin'],
            // Bible databases and logo/brand assets never pass through the helper: exempt by design.
        ];

        return $limits;
    }
}
