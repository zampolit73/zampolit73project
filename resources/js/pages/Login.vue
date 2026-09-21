<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import Button from '../components/ui/Button.vue';
import Input from '../components/ui/Input.vue';

const form = useForm({ username: '', password: '' });
const submit = () => form.post('/login');
</script>

<template>
    <Head title="Авторизация" />

    <main class="auth-page">
        <section class="auth-card">
            <p class="ds-label">PROJECT / ACCESS</p>
            <h1>Войти</h1>
            <p class="auth-card__description">Авторизуйся, чтобы продолжить работу с проектом.</p>

            <div v-if="form.errors.username" class="auth-card__error" role="alert">
                {{ form.errors.username }}
            </div>

            <form class="auth-form" @submit.prevent="submit">
                <label class="auth-form__label" for="username">Логин</label>
                <Input id="username" v-model="form.username" name="username" autocomplete="username" required />

                <label class="auth-form__label" for="password">Пароль</label>
                <Input id="password" v-model="form.password" name="password" type="password" autocomplete="current-password" required />

                <Button class="auth-form__submit" type="submit" :disabled="form.processing">
                    {{ form.processing ? 'Вход…' : 'Войти' }}
                </Button>
            </form>
        </section>
    </main>
</template>
