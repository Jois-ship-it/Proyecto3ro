<?php header("Content-Type: text/plain"); echo "CF-Ray visto por PHP: [".($_SERVER["HTTP_CF_RAY"]??"VACIO")."]";
