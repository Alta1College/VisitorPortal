<?php

/**
 * SPDX-FileCopyrightText: 2026 Simon Grant
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Badges;

use RuntimeException;

/**
 * A badge could not be rendered or sent. The message is safe to show administrators.
 */
class BadgePrintException extends RuntimeException {}
