<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import Alert from '../components/ui/Alert.vue';
import Badge from '../components/ui/Badge.vue';
import Button from '../components/ui/Button.vue';
import Card from '../components/ui/Card.vue';
import Input from '../components/ui/Input.vue';
import PageHeader from '../components/ui/PageHeader.vue';
</script>

<template>
    <Head title="Design System" />
    <AppLayout>
        <main class="ds-catalog">
            <PageHeader eyebrow="SYSTEM / UI" title="Design System" description="Живой каталог токенов и компонентов project-0." />

            <section class="ds-catalog__section">
                <h2>Buttons</h2>
                <div class="ds-catalog__row">
                    <Button>Primary</Button>
                    <Button variant="secondary">Secondary</Button>
                    <Button disabled>Loading…</Button>
                </div>
            </section>

            <section class="ds-catalog__section">
                <h2>Forms</h2>
                <Card><label class="ds-catalog__field"><span class="ds-label">Example input</span><Input placeholder="Введите значение…" /></label></Card>
            </section>

            <section class="ds-catalog__section">
                <h2>Feedback</h2>
                <div class="ds-catalog__row"><Badge>Neutral</Badge><Badge variant="success">Success</Badge><Badge variant="danger">Error</Badge></div>
                <Alert>Пример сообщения об ошибке с понятным следующим действием.</Alert>
            </section>
        </main>
    </AppLayout>
</template>
