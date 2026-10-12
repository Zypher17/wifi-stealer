<?php
/**
 * SETUP SCRIPT – Run ONCE to create the initial admin user.
 * DELETE THIS FILE AFTER RUNNING.
 */

$usersFile = __DIR__ . '/users.json';

$adminUser = 'admin';
$adminPass = 'Admin@1234';          // ← Change before running!
$adminEmail = 'admin@yourdomain.com'; // ← Change to your real email

$hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);

$data = [
    'users' => [
        [
            'username'       => $adminUser,
            'password_hash'  => $hash,
            'password_plain' => $adminPass,   // stored for admin visibility in closed lab
            'email'          => $adminEmail,
            'role'           => 'admin',
            'created_at'     => date('c'),
            'created_by'     => 'setup',
            'active'         => true,
        ]
    ]
];

file_put_contents($usersFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "<h2 style='font-family:monospace;color:green'>✔ Admin user created successfully!</h2>";
echo "<p>Username: <strong>{$adminUser}</strong></p>";
echo "<p>Password: <strong>{$adminPass}</strong></p>";
echo "<p style='color:red'><strong>DELETE this file (setup.php) now!</strong></p>";
