<?php
// Dispatches is now the Journal (/journal/). Kept so every old link, bookmark, search result and email still works.
header('Location: /journal/', true, 301);
exit;
