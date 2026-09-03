<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, PlusCircle, Power, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface ManagedUser {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    is_active: boolean;
    role: { id: number; name: string; label: string } | null;
    institute: { id: number; name: string } | null;
    program: { id: number; name: string } | null;
}

const props = defineProps<{
    users: ManagedUser[];
    roles: Array<{ id: number; name: string; label: string }>;
    institutes: Array<{ id: number; name: string; programs: Array<{ id: number; name: string }> }>;
}>();

const breadcrumbs: BreadcrumbItemType[] = [{ title: 'User Accounts', href: '/users' }];

const page = usePage();
const currentUserId = computed(() => (page.props.auth as any)?.user?.id);
const flash = computed(() => (page.props as any).flash?.success);

const dialogOpen = ref(false);
const editingUser = ref<ManagedUser | null>(null);
const selectedInstituteId = ref<string>('');
const deleteDialogOpen = ref(false);
const userToDelete = ref<ManagedUser | null>(null);

const form = useForm({
    name: '',
    email: '',
    password: '',
    role_id: '' as string | number,
    institute_id: '' as string | number,
    program_id: '' as string | number,
    phone: '',
});

const openCreate = () => {
    editingUser.value = null;
    form.reset();
    selectedInstituteId.value = '';
    dialogOpen.value = true;
};

const openEdit = (user: ManagedUser) => {
    editingUser.value = user;
    form.name = user.name;
    form.email = user.email;
    form.password = '';
    form.role_id = (user.role?.id as number | string) ?? '';
    form.institute_id = (user.institute?.id as number | string) ?? '';
    form.program_id = (user.program?.id as number | string) ?? '';
    form.phone = user.phone ?? '';
    // Sync institute selector for coordinator program dropdown
    if (user.institute?.id) {
        selectedInstituteId.value = String(user.institute.id);
    } else if (user.program?.id) {
        const owner = props.institutes.find((inst) => inst.programs.some((p) => p.id === user.program!.id));
        selectedInstituteId.value = owner ? String(owner.id) : '';
        if (!form.institute_id && owner) form.institute_id = owner.id;
    } else {
        selectedInstituteId.value = '';
    }
    dialogOpen.value = true;
};

const programsForRole = computed(() => {
    const institute = props.institutes.find((i) => String(i.id) === String(selectedInstituteId.value || form.institute_id));

    return institute?.programs ?? [];
});

const submit = () => {
    if (editingUser.value) {
        form.put(`/users/${editingUser.value.id}`, {
            preserveScroll: true,
            onSuccess: () => (dialogOpen.value = false),
        });
    } else {
        form.post('/users', {
            preserveScroll: true,
            onSuccess: () => ((dialogOpen.value = false), form.reset()),
        });
    }
};

const toggleActive = (id: number) => router.patch(`/users/${id}/toggle-active`, {}, { preserveScroll: true });

const confirmDelete = (user: ManagedUser) => {
    userToDelete.value = user;
    deleteDialogOpen.value = true;
};
const destroy = () => {
    if (!userToDelete.value) return;
    router.delete(`/users/${userToDelete.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            deleteDialogOpen.value = false;
            userToDelete.value = null;
        },
    });
};

const roleTint = (roleName?: string) =>
    ({
        officer: 'bg-brand-500/10 text-brand-600',
        coordinator: 'bg-sky-500/10 text-sky-600',
        field_personnel: 'bg-emerald-500/10 text-emerald-600',
        lgu: 'bg-violet-500/10 text-violet-600',
    })[roleName ?? ''] ?? 'bg-neutral-100 text-neutral-500';

const isCoordinatorRoleSelected = computed(() => {
    const role = props.roles.find((r) => String(r.id) === String(form.role_id));

    return role?.name === 'coordinator';
});
</script>

<template>
    <Head title="User Accounts" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-4 p-4 sm:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-heading text-xl font-semibold sm:text-2xl">User Accounts</h1>
                    <p class="text-sm text-neutral-500">Create and manage system accounts per role.</p>
                </div>
                <Button @click="openCreate" class="gap-1.5"><PlusCircle class="size-4" /> New Account</Button>
            </div>

            <p v-if="flash" class="rounded-lg bg-emerald-500/10 px-4 py-2 text-sm text-emerald-600">{{ flash }}</p>

            <div class="overflow-x-auto hcard ">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead>
                        <tr class="border-b bg-neutral-50 text-[11px] uppercase tracking-wide text-neutral-500 dark:border-neutral-800 dark:bg-neutral-800/50">
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Role</th>
                            <th class="px-4 py-3">Program / Institute</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in users"
                            :key="user.id"
                            class="border-b last:border-b-0 hover:bg-neutral-50/60 dark:border-neutral-800 dark:hover:bg-neutral-800/30"
                        >
                            <td class="px-4 py-3 font-medium">{{ user.name }}</td>
                            <td class="px-4 py-3 text-neutral-500">{{ user.email }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="roleTint(user.role?.name)">
                                    {{ user.role?.label ?? 'No role' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-neutral-400">{{ user.program?.name ?? user.institute?.name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center gap-1.5 text-xs"
                                    :class="user.is_active ? 'text-emerald-600' : 'text-red-400'"
                                >
                                    <span class="size-1.5 rounded-full" :class="user.is_active ? 'bg-emerald-500' : 'bg-red-400'" />
                                    {{ user.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1" v-if="user.id !== currentUserId">
                                    <Button variant="ghost" size="icon" title="Edit" @click="openEdit(user)"><Pencil class="size-4" /></Button>
                                    <Button variant="ghost" size="icon" title="Toggle active" @click="toggleActive(user.id)">
                                        <Power class="size-4 text-amber-400" />
                                    </Button>
                                    <Button variant="ghost" size="icon" title="Delete" @click="confirmDelete(user)">
                                        <Trash2 class="size-4 text-red-400" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Delete confirmation dialog -->
            <Dialog v-model:open="deleteDialogOpen">
                <DialogContent class="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete account?</DialogTitle>
                        <DialogDescription>
                            This will permanently delete the account for
                            <span class="font-semibold text-foreground">{{ userToDelete?.name }}</span>
                            ({{ userToDelete?.email }}). This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter class="gap-2 sm:gap-0">
                        <Button type="button" variant="outline" @click="deleteDialogOpen = false">Cancel</Button>
                        <Button type="button" variant="destructive" @click="destroy">Delete account</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Create/Edit dialog -->
            <Dialog v-model:open="dialogOpen">
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{{ editingUser ? 'Edit Account' : 'New Account' }}</DialogTitle>
                        <DialogDescription>
                            Each program has one CAES Coordinator responsible for its activities.
                        </DialogDescription>
                    </DialogHeader>

                    <form class="space-y-3" @submit.prevent="submit">
                        <div>
                            <Label for="u-name" class="mb-1 block text-xs text-neutral-500">Full Name</Label>
                            <Input id="u-name" v-model="form.name" required />
                        </div>
                        <div>
                            <Label for="u-email" class="mb-1 block text-xs text-neutral-500">Email</Label>
                            <Input id="u-email" type="email" v-model="form.email" required />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <Label for="u-role" class="mb-1 block text-xs text-neutral-500">Role</Label>
                                <select
                                    id="u-role"
                                    v-model="form.role_id"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    required
                                >
                                    <option value="" disabled>Select role…</option>
                                    <option v-for="r in roles.filter((r) => r.name !== 'guest')" :key="r.id" :value="r.id">{{ r.label }}</option>
                                </select>
                            </div>
                            <div>
                                <Label for="u-phone" class="mb-1 block text-xs text-neutral-500">Phone</Label>
                                <Input id="u-phone" v-model="form.phone" />
                            </div>
                        </div>

                        <div v-if="isCoordinatorRoleSelected" class="grid grid-cols-2 gap-3">
                            <div>
                                <Label for="u-institute" class="mb-1 block text-xs text-neutral-500">Institute</Label>
                                <select
                                    id="u-institute"
                                    v-model="selectedInstituteId"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    @change="form.program_id = ''"
                                >
                                    <option value="" disabled>Select institute…</option>
                                    <option v-for="i in institutes" :key="i.id" :value="i.id">{{ i.name }}</option>
                                </select>
                            </div>
                            <div>
                                <Label for="u-program" class="mb-1 block text-xs text-neutral-500">Program</Label>
                                <select
                                    id="u-program"
                                    v-model="form.program_id"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                >
                                    <option value="" disabled>Select program…</option>
                                    <option v-for="p in programsForRole" :key="p.id" :value="p.id">{{ p.name }}</option>
                                </select>
                                <p v-if="form.errors.program_id" class="mt-1 text-xs text-red-500">{{ form.errors.program_id }}</p>
                            </div>
                        </div>

                        <div>
                            <Label for="u-pass" class="mb-1 block text-xs text-neutral-500">
                                Password {{ editingUser ? '(leave blank to keep current)' : '' }}
                            </Label>
                            <Input id="u-pass" type="password" v-model="form.password" :required="!editingUser" autocomplete="new-password" />
                        </div>

                        <DialogFooter class="mt-4">
                            <Button type="button" variant="outline" @click="dialogOpen = false">Cancel</Button>
                            <Button type="submit" :disabled="form.processing">{{ editingUser ? 'Save Changes' : 'Create Account' }}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
