<?php

$client = new swoole_client(SWOOLE_SOCK_TCP);
$client->set(
    array(
        'open_eof_split' => true,
        'package_eof' => "\r\n",
        'package_max_length' => 8 * 1024 * 1024,
    )
);

if (!$client->connect('127.0.0.1', 9504)) {
    exit("connect failed\n");
}

$func = "send_test" . intval(empty($argv[1]) ? 3 : $argv[1]);

for ($l = 0; $l < 1; $l++) {
    for ($i = 0; $i < 10; $i++) {
        $len = rand(100000, 200000);
        $func($client, $len);
    }
}

function send_test3($client, $len)
{
    $data = str_repeat('A', $len) . "\r\n";
    $chunks = str_split($data, 4000);
    foreach ($chunks as $ch) {
        $client->send($ch);
    }
    echo "send : " . strlen($data) . "\n";
//    $data = $client->recv();
//    echo "recv : " . strlen($data) . "\n";
}

function send_test2($client, $len)
{
    $data = pack('N', $len + 4);
    $data .= str_repeat('A', $len) . rand(100000, 999999);
    $client->send($data);

    $data = $client->recv();
}

function send_test1($client, $len)
{
    $client->send(pack('N', $len + 4));
    usleep(10);
    $client->send(str_repeat('A', $len) . rand(1000, 9999));
    $data = $client->recv();
}
