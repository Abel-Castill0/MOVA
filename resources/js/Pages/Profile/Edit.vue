<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdateAvatarForm from './Partials/UpdateAvatarForm.vue';
import NotificationPreferencesForm from './Partials/NotificationPreferencesForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <AppLayout title="Mi perfil">
        <div class="max-w-2xl space-y-6">
            <div class="p-4 sm:p-8 bg-white rounded-2xl border border-gray-100 shadow-sm">
                <UpdateAvatarForm />
            </div>

            <div class="p-4 sm:p-8 bg-white rounded-xl border border-gray-200">
                <UpdateProfileInformationForm
                    :must-verify-email="mustVerifyEmail"
                    :status="status"
                    class="max-w-xl"
                />
            </div>

            <div class="p-4 sm:p-8 bg-white rounded-xl border border-gray-200">
                <NotificationPreferencesForm class="max-w-xl" />
            </div>

            <div class="p-4 sm:p-8 bg-white rounded-xl border border-gray-200">
                <UpdatePasswordForm class="max-w-xl" />
            </div>

            <div class="p-4 sm:p-8 bg-white rounded-xl border border-gray-200">
                <DeleteUserForm class="max-w-xl" />
            </div>
        </div>
    </AppLayout>
</template>
