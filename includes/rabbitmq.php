<?php
/**
 * RabbitMQ — cienki wrapper nad php-amqplib.
 *
 * Połączenie jest leniwe (AMQPLazyConnection) i współdzielone w ramach
 * jednego procesu PHP. Kolejki tworzone są jako durable — przetrwają
 * restart brokera, wiadomości są persistent.
 */

use PhpAmqpLib\Connection\AMQPLazyConnection;
use PhpAmqpLib\Message\AMQPMessage;

function rabbit_connection(): AMQPLazyConnection
{
    static $conn = null;
    if ($conn === null || !$conn->isConnected()) {
        $conn = new AMQPLazyConnection(
            host:     getenv('RABBITMQ_HOST')  ?: 'rabbitmq',
            port:     (int)(getenv('RABBITMQ_PORT')  ?: 5672),
            user:     getenv('RABBITMQ_USER')  ?: 'feer',
            password: getenv('RABBITMQ_PASS')  ?: 'feer',
            vhost:    getenv('RABBITMQ_VHOST') ?: '/'
        );
    }
    return $conn;
}

/**
 * Publikuje wiadomość na kolejkę. Kolejka jest tworzona jeśli nie istnieje.
 *
 * @param string $queue   Nazwa kolejki (np. 'mail.send')
 * @param array  $payload Dane serializowane jako JSON
 */
function rabbit_publish(string $queue, array $payload): void
{
    $conn    = rabbit_connection();
    $channel = $conn->channel();

    $channel->queue_declare(
        queue:       $queue,
        passive:     false,
        durable:     true,   // przetrwa restart brokera
        exclusive:   false,
        auto_delete: false
    );

    $msg = new AMQPMessage(
        body:       json_encode($payload, JSON_UNESCAPED_UNICODE),
        properties: ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
    );

    $channel->basic_publish($msg, exchange: '', routing_key: $queue);
    $channel->close();
}

/**
 * Konsumuje do $limit wiadomości z kolejki (tryb pull — bez blokowania).
 * Dla każdej wiadomości woła $handler(array $payload): bool.
 * Handler zwraca true → ACK, false → NACK (re-queue).
 *
 * @return int Liczba przetworzonych wiadomości
 */
function rabbit_consume_batch(string $queue, callable $handler, int $limit = 20): int
{
    $conn    = rabbit_connection();
    $channel = $conn->channel();

    $channel->queue_declare(
        queue:       $queue,
        passive:     false,
        durable:     true,
        exclusive:   false,
        auto_delete: false
    );

    $processed = 0;
    while ($processed < $limit) {
        $msg = $channel->basic_get($queue);
        if ($msg === null) {
            break;
        }

        $payload = json_decode($msg->body, true) ?? [];
        $ok      = $handler($payload);

        if ($ok) {
            $channel->basic_ack($msg->getDeliveryTag());
        } else {
            $channel->basic_nack($msg->getDeliveryTag(), requeue: true);
        }
        $processed++;
    }

    $channel->close();
    return $processed;
}
