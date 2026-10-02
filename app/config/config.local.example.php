<?php
// Copy to config.local.php and fill in your host's details. Only list what differs.
return [
  'db' => [
    'host' => 'localhost',
    'name' => 'your_database',
    'user' => 'your_user',
    'pass' => 'your_password',
  ],
  // For a public demo: logins you publish, which can look around but not save.
  // Leave this out on a real election.
  // 'demo' => [
  //   'read_only_admins' => ['demo-admin'],
  //   'read_only_voters' => ['CS/MK/0001/09/23', 'CS/MK/0002/09/23'],
  //   'show_logins' => [
  //     ['label' => 'a voter',  'id' => 'CS/MK/0001/09/23', 'secret' => '246810'],
  //     ['label' => 'an admin', 'id' => 'demo-admin',       'secret' => 'demo-admin-pass'],
  //   ],
  // ],
];
