<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { appLinks } from '../navigation.js';
import Button from '../components/ui/Button.vue';

const page = usePage();
const menuOpen = ref(false);
const installAvailable = ref(false);
const user = computed(() => page.props.auth?.user ?? null);
const currentPath = computed(() => page.url.split('?')[0]);
const links = computed(() => appLinks.filter((link) => {
    if (user.value) {
        return link.auth && (!link.roles || link.roles.includes(user.value.role));
    }

    return link.guest;
}));
const isActive = (href) => href === '/projects'
    ? currentPath.value === '/projects' || currentPath.value.startsWith('/projects/')
    : currentPath.value === href;
const closeMenu = () => { menuOpen.value = false; };
const requestInstall = () => window.dispatchEvent(new Event('pwa-install-request'));

if (typeof window !== 'undefined') {
    window.addEventListener('pwa-install-available', () => { installAvailable.value = true; });
}
</script>

<template>
    <button
        class="menu-toggle"
        type="button"
        aria-controls="sidebar"
        :aria-expanded="menuOpen"
        @click="menuOpen = !menuOpen"
    >
        <span></span>
        <span></span>
        <span></span>
        <span class="sr-only">{{ menuOpen ? 'Закрыть меню' : 'Открыть меню' }}</span>
    </button>

    <aside id="sidebar" class="sidebar" :class="{ 'is-open': menuOpen }">
        <div class="sidebar__brand">ZAMPOLIT73PROJECT</div>

        <nav class="sidebar__nav" aria-label="Основная навигация">
            <template v-for="link in links" :key="link.href">
                <Link
                    :href="link.href"
                    class="sidebar__link"
                    :class="{ 'is-active': isActive(link.href) }"
                    @click="closeMenu"
                >
                    <span>{{ String(link.number).padStart(2, '0') }} —</span>
                    {{ link.label }}
                </Link>
            </template>
        </nav>

        <div class="sidebar__account">
            <template v-if="user">
                <div class="sidebar__account-label">Аккаунт</div>
                <div class="sidebar__account-name">{{ user.username }}</div>
                <div class="sidebar__account-role">
                    {{ user.role === 'admin' ? 'Администратор' : 'Пользователь' }}
                </div>
                <Button v-if="installAvailable" variant="secondary" type="button" class="sidebar__push" @click="requestInstall">Установить приложение</Button>
                <Button
                    type="button"
                    variant="secondary"
                    class="sidebar__logout"
                    @click="router.post('/logout', {}, { preserveScroll: true, onSuccess: closeMenu })"
                >
                    Выйти
                </Button>
            </template>
        </div>
    </aside>

    <main class="content">
        <Transition name="page" mode="out-in" appear>
            <div :key="page.component" class="page-transition">
                <slot />
            </div>
        </Transition>
    </main>

    <button
        v-if="menuOpen"
        class="sidebar-backdrop"
        type="button"
        aria-label="Закрыть меню"
        @click="closeMenu"
    ></button>
</template>
