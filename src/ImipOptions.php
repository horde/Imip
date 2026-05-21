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
 * Configuration options for iMIP message construction.
 */
final readonly class ImipOptions
{
    public function __construct(
        public string $charset = 'UTF-8',
        public string $prodId = '-//Horde//Horde iMIP//EN',
        public bool $multipart = true,
    ) {}
}
