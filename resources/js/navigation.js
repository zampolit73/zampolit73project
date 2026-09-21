export const appLinks = [
    { href: '/', label: 'Главная', number: '0', guest: true, auth: true },
    { href: '/design-system', label: 'Дизайн-система', number: '1', auth: true, roles: ['admin', 'moderator'] },
    { href: '/login', label: 'Войти', number: '→', guest: true },
];
