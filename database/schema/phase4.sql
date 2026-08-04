BEGIN;

CREATE SCHEMA IF NOT EXISTS {{schema}};

CREATE TABLE {{schema}}.workflows (
    id uuid PRIMARY KEY,
    handle varchar(100) NOT NULL UNIQUE,
    name varchar(255) NOT NULL CHECK (btrim(name) <> ''),
    version integer NOT NULL DEFAULT 1 CHECK (version >= 1),
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
);

CREATE TABLE {{schema}}.workflow_states (
    workflow_id uuid NOT NULL REFERENCES {{schema}}.workflows (id) ON DELETE CASCADE,
    state_key varchar(40) NOT NULL CHECK (state_key IN ('draft', 'review', 'published', 'archived')),
    name varchar(255) NOT NULL CHECK (btrim(name) <> ''),
    is_initial boolean NOT NULL DEFAULT false,
    is_public boolean NOT NULL DEFAULT false,
    PRIMARY KEY (workflow_id, state_key)
);

CREATE UNIQUE INDEX workflow_states_one_initial
    ON {{schema}}.workflow_states (workflow_id)
    WHERE is_initial;

CREATE TABLE {{schema}}.workflow_transitions (
    workflow_id uuid NOT NULL,
    from_state varchar(40) NOT NULL,
    to_state varchar(40) NOT NULL,
    required_capability varchar(190),
    PRIMARY KEY (workflow_id, from_state, to_state),
    CONSTRAINT workflow_transitions_not_self CHECK (from_state <> to_state),
    CONSTRAINT workflow_transitions_closed_map CHECK (
        (from_state = 'draft' AND to_state IN ('review', 'archived'))
        OR (from_state = 'review' AND to_state IN ('draft', 'published', 'archived'))
        OR (from_state = 'published' AND to_state IN ('draft', 'archived'))
        OR (from_state = 'archived' AND to_state = 'draft')
    ),
    CONSTRAINT workflow_transitions_from_state_fk
        FOREIGN KEY (workflow_id, from_state)
        REFERENCES {{schema}}.workflow_states (workflow_id, state_key)
        ON DELETE CASCADE,
    CONSTRAINT workflow_transitions_to_state_fk
        FOREIGN KEY (workflow_id, to_state)
        REFERENCES {{schema}}.workflow_states (workflow_id, state_key)
        ON DELETE CASCADE
);

CREATE TABLE {{schema}}.content_types (
    id uuid PRIMARY KEY,
    workflow_id uuid NOT NULL REFERENCES {{schema}}.workflows (id) ON DELETE RESTRICT,
    handle varchar(100) NOT NULL UNIQUE CHECK (handle ~ '^[a-z][a-z0-9_]*$'),
    name varchar(255) NOT NULL CHECK (btrim(name) <> ''),
    field_schema jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(field_schema) = 'object'),
    version integer NOT NULL DEFAULT 1 CHECK (version >= 1),
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    UNIQUE (id, workflow_id)
);

CREATE TABLE {{schema}}.content_entries (
    id uuid PRIMARY KEY,
    content_type_id uuid NOT NULL,
    workflow_id uuid NOT NULL,
    workflow_state_key varchar(40) NOT NULL,
    title varchar(255) NOT NULL CHECK (btrim(title) <> ''),
    slug varchar(160) NOT NULL CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    data jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(data) = 'object'),
    publish_at timestamptz,
    unpublish_at timestamptz,
    version integer NOT NULL DEFAULT 1 CHECK (version >= 1),
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    deleted_at timestamptz,
    CONSTRAINT content_entries_publication_window
        CHECK (publish_at IS NULL OR unpublish_at IS NULL OR publish_at < unpublish_at),
    CONSTRAINT content_entries_type_workflow_fk
        FOREIGN KEY (content_type_id, workflow_id)
        REFERENCES {{schema}}.content_types (id, workflow_id)
        ON DELETE RESTRICT,
    CONSTRAINT content_entries_workflow_state_fk
        FOREIGN KEY (workflow_id, workflow_state_key)
        REFERENCES {{schema}}.workflow_states (workflow_id, state_key)
        ON DELETE RESTRICT
);

CREATE UNIQUE INDEX content_entries_live_slug_unique
    ON {{schema}}.content_entries (content_type_id, slug)
    WHERE deleted_at IS NULL;

CREATE INDEX content_entries_publication_lookup
    ON {{schema}}.content_entries (workflow_state_key, publish_at, unpublish_at)
    WHERE deleted_at IS NULL;

CREATE INDEX content_entries_data_gin
    ON {{schema}}.content_entries USING gin (data);

CREATE TABLE {{schema}}.content_revisions (
    id uuid PRIMARY KEY,
    content_entry_id uuid NOT NULL REFERENCES {{schema}}.content_entries (id) ON DELETE RESTRICT,
    revision_number integer NOT NULL CHECK (revision_number >= 1),
    snapshot jsonb NOT NULL CHECK (jsonb_typeof(snapshot) = 'object'),
    checksum char(64) NOT NULL CHECK (checksum ~ '^[0-9a-f]{64}$'),
    created_at timestamptz NOT NULL,
    UNIQUE (content_entry_id, revision_number)
);

CREATE FUNCTION {{schema}}.reject_content_revision_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'content revisions are immutable';
END;
$$;

CREATE TRIGGER content_revisions_immutable
    BEFORE UPDATE OR DELETE ON {{schema}}.content_revisions
    FOR EACH ROW
    EXECUTE FUNCTION {{schema}}.reject_content_revision_mutation();

CREATE TABLE {{schema}}.navigation_menus (
    id uuid PRIMARY KEY,
    handle varchar(100) NOT NULL UNIQUE CHECK (handle ~ '^[a-z][a-z0-9_]*$'),
    title varchar(255) NOT NULL CHECK (btrim(title) <> ''),
    version integer NOT NULL DEFAULT 1 CHECK (version >= 1),
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
);

CREATE TABLE {{schema}}.navigation_items (
    id uuid PRIMARY KEY,
    menu_id uuid NOT NULL REFERENCES {{schema}}.navigation_menus (id) ON DELETE CASCADE,
    parent_id uuid,
    title varchar(255) NOT NULL CHECK (btrim(title) <> ''),
    slug varchar(160) NOT NULL CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    path varchar(2048) NOT NULL CHECK (path ~ '^/[a-z0-9]+(-[a-z0-9]+)*(/[a-z0-9]+(-[a-z0-9]+)*)*$'),
    position integer NOT NULL DEFAULT 0 CHECK (position >= 0),
    version integer NOT NULL DEFAULT 1 CHECK (version >= 1),
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    UNIQUE (menu_id, id),
    UNIQUE (menu_id, path),
    CONSTRAINT navigation_items_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id),
    CONSTRAINT navigation_items_parent_same_menu_fk
        FOREIGN KEY (menu_id, parent_id)
        REFERENCES {{schema}}.navigation_items (menu_id, id)
        ON DELETE RESTRICT
        DEFERRABLE INITIALLY DEFERRED
);

CREATE UNIQUE INDEX navigation_items_root_slug_unique
    ON {{schema}}.navigation_items (menu_id, slug)
    WHERE parent_id IS NULL;

CREATE UNIQUE INDEX navigation_items_child_slug_unique
    ON {{schema}}.navigation_items (menu_id, parent_id, slug)
    WHERE parent_id IS NOT NULL;

CREATE INDEX navigation_items_parent_position
    ON {{schema}}.navigation_items (menu_id, parent_id, position, id);

CREATE FUNCTION {{schema}}.ensure_navigation_item_tree()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    expected_path text;
    cycle_found boolean;
BEGIN
    IF NEW.parent_id IS NULL THEN
        expected_path := '/' || NEW.slug;
    ELSE
        SELECT parent.path || '/' || NEW.slug
          INTO expected_path
          FROM {{schema}}.navigation_items AS parent
         WHERE parent.menu_id = NEW.menu_id
           AND parent.id = NEW.parent_id;

        IF expected_path IS NULL THEN
            RAISE EXCEPTION 'parent menu item % does not exist in menu %', NEW.parent_id, NEW.menu_id;
        END IF;

        WITH RECURSIVE ancestors AS (
            SELECT item.id, item.parent_id
              FROM {{schema}}.navigation_items AS item
             WHERE item.menu_id = NEW.menu_id
               AND item.id = NEW.parent_id
            UNION ALL
            SELECT item.id, item.parent_id
              FROM {{schema}}.navigation_items AS item
              JOIN ancestors ON item.id = ancestors.parent_id
             WHERE item.menu_id = NEW.menu_id
        )
        SELECT EXISTS (SELECT 1 FROM ancestors WHERE id = NEW.id)
          INTO cycle_found;

        IF cycle_found THEN
            RAISE EXCEPTION 'navigation item move would create a cycle';
        END IF;
    END IF;

    IF NEW.path <> expected_path THEN
        RAISE EXCEPTION 'navigation item path must be %, received %', expected_path, NEW.path;
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER navigation_items_tree_guard
    BEFORE INSERT OR UPDATE OF menu_id, parent_id, slug, path
    ON {{schema}}.navigation_items
    FOR EACH ROW
    EXECUTE FUNCTION {{schema}}.ensure_navigation_item_tree();

COMMIT;
