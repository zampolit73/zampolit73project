export const appLinks = [
    { href: '/', label: 'Главная', number: '0', guest: true, auth: true },
    { href: '/projects', label: 'Проекты', number: '1', auth: true },
    { href: '/admin/users', label: 'Пользователи', number: '2', auth: true, roles: ['admin'] },
    { href: '/stas', label: 'Стас', number: '3', auth: true, roles: ['admin'] },
    { href: '/design-system', label: 'Дизайн-система', number: '4', auth: true, roles: ['admin'] },
    { href: '/tests', label: 'Tests', number: '5', auth: true, roles: ['admin'] },
    { href: '/login', label: 'Войти', number: '→', guest: true },
];
