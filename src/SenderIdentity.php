<?php

declare(strict_types=1);

/**
 * Copyright 2002-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author    Mike Cochrane <mike@graftonhall.co.nz>
 * @author    Chuck Hagenbuch <chuck@horde.org>
 * @author    Steffen Hansen <steffen@klaralvdalens-datakonsult.se>
 * @author    Gunnar Wrobel <wrobel@pardus.de>
 * @author    Michael J Rubinsky <mrubinsk@horde.org>
 * @author    Ralf Lang <ralf.lang@ralf-lang.de>
 * @category  Horde
 * @copyright 2002-2026 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Imip
 */

namespace Horde\Imip;

/**
 * Provides identity information about the sender of an iMIP message.
 *
 * Applications implement this interface to supply sender email, display name,
 * and reply-to address from their identity system.
 */
interface SenderIdentity
{
    /**
     * Get the sender's email address.
     */
    public function getEmail(): string;

    /**
     * Get the sender's display name.
     */
    public function getCommonName(): string;

    /**
     * The formatted "From" header value, e.g. "Display Name <email@example.com>".
     */
    public function getFrom(): string;

    /**
     * Optional Reply-To address. Return null if same as From.
     */
    public function getReplyTo(): ?string;
}
