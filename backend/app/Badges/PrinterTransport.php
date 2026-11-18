<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Badges;

/**
 * Delivers printer bytes. The default sends them raw over TCP (port 9100); tests swap it.
 */
interface PrinterTransport
{
    /**
     * @throws BadgePrintException
     */
    public function send(BadgePrinter $printer, string $bytes): void;
}
