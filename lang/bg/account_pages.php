<?php

/* ACC-3a: the storefront account pages (login, register, forgot / reset password, verify, email confirm, /account). bg/en kept together. `js` goes into data attributes of the page (no inline script). */
return [
    'title' => 'Моят профил',

    'login_title' => 'Вход',
    'register_title' => 'Регистрация',
    'forgot_title' => 'Забравена парола',
    'reset_title' => 'Нова парола',
    'verify_title' => 'Потвърждаване на имейла',
    'confirm_title' => 'Потвърждаване на новия имейл',

    'email' => 'Имейл',
    'password' => 'Парола',
    'new_password' => 'Нова парола',
    'new_password_repeat' => 'Повтори новата парола',
    'show_password' => 'Покажи',
    'hide_password' => 'Скрий',
    'remember' => 'Запомни ме',
    'password_hint' => 'От 8 до 72 символа. Да не е същата като имейла ти и да не е често срещана парола.',

    'login_submit' => 'Вход',
    'register_submit' => 'Регистрация',
    'forgot_submit' => 'Изпрати връзка',
    'reset_submit' => 'Смени паролата',
    'verify_button' => 'Потвърди имейла',
    'confirm_button' => 'Потвърди новия имейл',
    'resend_button' => 'Изпрати писмото отново',

    'forgot_link' => 'Забравена парола?',
    'register_link' => 'Нямаш профил? Регистрация',
    'login_link' => 'Вече имаш профил? Вход',
    'login_only_link' => 'Вход',
    'forgot_again_link' => 'Забравена парола',
    'back_to_account_link' => 'Опитай отново от профила',
    'forgot_intro' => 'Напиши имейла си и ще ти изпратим връзка за нова парола.',
    'verify_intro' => 'Натисни бутона, за да потвърдиш имейла си.',
    'confirm_intro' => 'Натисни бутона, за да потвърдиш новия си имейл.',
    'noscript' => 'Тази страница работи с JavaScript. Включи го в браузъра си и опитай пак.',

    'notice_password_reset' => 'Паролата е сменена. Влез с новата парола.',

    'home_greeting' => 'Здравей, :email',
    'logout' => 'Изход',

    'js' => [
        'sending' => 'Момент…',
        'login_invalid' => 'Грешен имейл или парола.',
        'login_unverified' => 'Потвърди имейла си. Писмото с връзка те чака във входящата ти поща.',
        'resend_done' => 'Ако за този имейл има профил, който чака потвърждение, изпратихме ново писмо.',
        'too_many' => 'Твърде много опити, опитай пак след :minutes мин.',
        'too_many_soon' => 'Твърде много опити, опитай пак след малко.',
        'generic_error' => 'Нещо се обърка. Опитай пак след малко.',
        'register_done' => 'Изпратихме ти писмо на :email. Отвори го, за да потвърдиш профила.',
        'forgot_done' => 'Ако има профил с този имейл, изпратихме му връзка за нова парола.',
        'password_mismatch' => 'Паролите не съвпадат.',
        'reset_invalid' => 'Връзката е невалидна или е изтекла.',
        'verify_done' => 'Имейлът е потвърден. Вече можеш да влезеш.',
        'verify_invalid' => 'Връзката е невалидна или е изтекла.',
        'confirm_done' => 'Новият имейл е потвърден. Влез с него.',
        'confirm_invalid' => 'Връзката е невалидна или е изтекла.',
        'email_required' => 'Напиши имейл.',
        'password_required' => 'Напиши парола.',
    ],
];
