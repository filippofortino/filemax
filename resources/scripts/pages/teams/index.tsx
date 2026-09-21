import { Form, Head, usePage } from '@inertiajs/react';
import { ErrorMessage, Shell } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import type { SharedProps, Team, User } from '@/lib/types';
import { store, update } from '@/routes/teams';
import { destroy, store as addMember } from '@/routes/teams/members';

type Member = Pick<User, 'id' | 'name' | 'email'>;
type ManagedTeam = Team & { users: Member[] };

export default function Teams({
    teams,
    users,
}: {
    teams: ManagedTeam[];
    users: Member[];
}) {
    const status = usePage<SharedProps>().props.status;
    return (
        <Shell active="teams">
            <Head title="Teams" />
            <main className="mx-auto w-full max-w-[1064px] px-5 py-12 sm:px-8">
                <div className="mb-8 flex flex-col gap-2">
                    <h1>Teams</h1>
                    <p className="muted">
                        Manage who can receive transfers shared with each team.
                    </p>
                </div>
                {status && (
                    <p className="notice mb-6" role="status">
                        {status}
                    </p>
                )}
                <section
                    className="mb-6 rounded-xl border bg-white p-6"
                    aria-labelledby="create-team-title"
                >
                    <h2 id="create-team-title" className="mb-4 text-xl">
                        Create a team
                    </h2>
                    <Form
                        action={store()}
                        resetOnSuccess
                        className="flex flex-col gap-3 sm:flex-row sm:items-end"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="flex flex-1 flex-col gap-2">
                                    <label
                                        htmlFor="team-name"
                                        className="field-label"
                                    >
                                        Team name
                                    </label>
                                    <input
                                        id="team-name"
                                        name="name"
                                        className="field"
                                        placeholder="e.g. Creative Studio"
                                        required
                                        maxLength={255}
                                        aria-invalid={!!errors.name}
                                    />
                                    <ErrorMessage>{errors.name}</ErrorMessage>
                                </div>
                                <Button disabled={processing}>
                                    {processing ? 'Creating…' : 'Create team'}
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
                <div className="flex flex-col gap-6">
                    {teams.length === 0 && (
                        <div className="rounded-xl border bg-white p-10 text-center">
                            <h2 className="mb-2 text-xl">No teams yet</h2>
                            <p className="muted">
                                Create your first team, then add registered
                                colleagues.
                            </p>
                        </div>
                    )}
                    {teams.map((team) => (
                        <TeamCard key={team.id} team={team} users={users} />
                    ))}
                </div>
            </main>
        </Shell>
    );
}

function TeamCard({ team, users }: { team: ManagedTeam; users: Member[] }) {
    const availableUsers = users.filter(
        (user) => !team.users.some((member) => member.id === user.id),
    );
    return (
        <section
            className="rounded-xl border bg-white p-6"
            aria-label={`${team.name} team`}
        >
            <div className="mb-6 flex flex-col gap-4">
                <div className="flex items-center justify-between gap-3">
                    <h2 className="text-xl">{team.name}</h2>
                    <span className="muted text-sm">
                        {team.users_count}{' '}
                        {team.users_count === 1 ? 'member' : 'members'}
                    </span>
                </div>
                <Form
                    action={update(team.id)}
                    className="flex flex-col gap-3 sm:flex-row sm:items-end"
                    options={{ preserveScroll: true }}
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="flex flex-1 flex-col gap-2">
                                <label
                                    className="field-label"
                                    htmlFor={`name-${team.id}`}
                                >
                                    Team name
                                </label>
                                <input
                                    className="field"
                                    name="name"
                                    id={`name-${team.id}`}
                                    defaultValue={team.name}
                                    maxLength={255}
                                    required
                                    aria-invalid={!!errors.name}
                                />
                                <ErrorMessage>{errors.name}</ErrorMessage>
                            </div>
                            <Button variant="outline" disabled={processing}>
                                {processing ? 'Saving…' : 'Rename'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
            <div className="divide-y border-y">
                {team.users.length === 0 && (
                    <p className="muted py-5 text-sm">
                        This team has no members yet.
                    </p>
                )}
                {team.users.map((member) => (
                    <div
                        key={member.id}
                        className="flex items-center justify-between gap-4 py-4"
                    >
                        <div className="min-w-0">
                            <strong className="text-sm">{member.name}</strong>
                            <p className="muted text-sm break-all">
                                {member.email}
                            </p>
                        </div>
                        <Form
                            action={destroy({ team: team.id, user: member.id })}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    disabled={processing}
                                    aria-label={`Remove ${member.name} from ${team.name}`}
                                >
                                    Remove
                                </Button>
                            )}
                        </Form>
                    </div>
                ))}
            </div>
            <Form
                action={addMember(team.id)}
                resetOnSuccess
                options={{ preserveScroll: true }}
                className="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="flex flex-1 flex-col gap-2">
                            <label
                                className="field-label"
                                htmlFor={`member-${team.id}`}
                            >
                                Add a registered colleague
                            </label>
                            <select
                                id={`member-${team.id}`}
                                name="user_id"
                                className="field"
                                defaultValue=""
                                required
                                disabled={availableUsers.length === 0}
                                aria-invalid={!!errors.user_id}
                            >
                                <option value="" disabled>
                                    {availableUsers.length === 0
                                        ? 'All registered colleagues are members'
                                        : 'Choose a colleague'}
                                </option>
                                {availableUsers.map((user) => (
                                    <option key={user.id} value={user.id}>
                                        {user.name} — {user.email}
                                    </option>
                                ))}
                            </select>
                            <ErrorMessage>{errors.user_id}</ErrorMessage>
                        </div>
                        <Button
                            disabled={processing || availableUsers.length === 0}
                        >
                            {processing ? 'Adding…' : 'Add member'}
                        </Button>
                    </>
                )}
            </Form>
        </section>
    );
}
