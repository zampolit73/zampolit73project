export const appLinks = [
    { href: '/', label: 'Главная', number: '0', guest: true, auth: true },
    { href: '/stas', label: 'Стас', number: '1', guest: true, auth: true },
    { href: '/projects', label: 'Проекты', number: '2', guest: true, auth: true },
    { href: '/design-system', label: 'Дизайн-система', number: '3', auth: true, roles: ['admin', 'moderator'] },
    { href: '/tests', label: 'Tests', number: '4', auth: true },
    { href: '/login', label: 'Войти', number: '→', guest: true },
];
