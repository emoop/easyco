<?php

// Picture uploads (ML-0): a picture is stored without its hidden data (EXIF / GPS); a format this server cannot read is refused. bg and en change together.
return [
    'not_cleanable' => 'This format is not supported on this server — install ImageMagick or convert the picture.',
];
