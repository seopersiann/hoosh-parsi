<?php
return [
    'repository' => 'seopersiann/hoosh-parsi',
    'branch' => 'main',
    'public_root' => '/home/pendariu/domains/hoosh-parsi.ir/public_html',
    'mode' => 'dry-run', // Change to live only after a successful dry-run on this host.
    'token_file' => __DIR__ . '/github-token.txt', // Optional for a public repository.
    'max_archive_bytes' => 52428800,
    'max_release_bytes' => 104857600,
    'max_files' => 3000,
    // Whole paths, not individual images/pages. New files under these rules are automatic.
    'allowed_roots' => ['assets'],
    'allowed_extensions' => ['html', 'css', 'js', 'xml', 'txt', 'webp', 'png', 'jpg', 'jpeg', 'svg', 'ico', 'avif', 'woff', 'woff2', 'ttf', 'json', 'mp4'],
    'protected_paths' => ['uploads', 'media', 'storage', 'wp-content', 'assets/uploads', 'assets/user-uploads'],
];
