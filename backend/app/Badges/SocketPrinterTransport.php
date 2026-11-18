<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Badges;

/**
 * Raw TCP printing ("JetDirect", port 9100): connect, write the job, close.
 */
class SocketPrinterTransport implements PrinterTransport
{
    public function __construct(private readonly float $timeoutSeconds = 5.0) {}

    public function send(BadgePrinter $printer, string $bytes): void
    {
        $errno = 0;
        $errstr = '';
        $address = sprintf('tcp://%s:%d', str_contains($printer->host, ':') ? '['.$printer->host.']' : $printer->host, $printer->port);
        $socket = @stream_socket_client($address, $errno, $errstr, $this->timeoutSeconds);

        if ($socket === false) {
            throw new BadgePrintException(__('Drucker :host:port nicht erreichbar (:error).', ['host' => $printer->host, 'port' => $printer->port, 'error' => $errstr ?: $errno]));
        }

        stream_set_timeout($socket, (int) ceil($this->timeoutSeconds * 2));

        try {
            $written = 0;
            $length = strlen($bytes);

            while ($written < $length) {
                $result = @fwrite($socket, substr($bytes, $written, 8192));

                if ($result === false || $result === 0) {
                    throw new BadgePrintException(__('Die Verbindung zum Drucker :host wurde unterbrochen.', ['host' => $printer->host]));
                }

                $written += $result;
            }

            fflush($socket);
        } finally {
            fclose($socket);
        }
    }
}
