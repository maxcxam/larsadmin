<?php

return [
    'xss' => '</script><script>alert(1)</script><!--',
    'attr' => '"><img src=x onerror=alert(2)>',
];
