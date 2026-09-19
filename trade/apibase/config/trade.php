<?php

return [
    // How long the code emailed to a visitor stays good for confirming an enquiry.
    'enquiry_code_minutes' => (int) env('TRADE_ENQUIRY_CODE_MINUTES', 30),
];
