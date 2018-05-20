-- TOPLINK SECTION
CREATE SCHEMA toplink;

-- Table: toplink.access

-- DROP TABLE toplink.access;

CREATE TABLE toplink.access
(
  login character varying(16) NOT NULL,
  password character varying(1024) NOT NULL,
  roles character varying(256),
  active boolean,
  id_user integer NOT NULL DEFAULT nextval('toplink.users_id_user_seq'::regclass),
  CONSTRAINT users_login_key UNIQUE (login)
)
WITH (
  OIDS=FALSE
);
ALTER TABLE toplink.access
  OWNER TO ue6lindbc6iam1;

-- Table: toplink.obb_bilateral_quota

-- DROP TABLE toplink.obb_bilateral_quota;

CREATE TABLE toplink.obb_bilateral_quota
(
  event__c character varying(18),
  sp integer,
  pf integer,
  id serial NOT NULL,
  CONSTRAINT obb_bilateral_quota_pkey PRIMARY KEY (id)
)
WITH (
  OIDS=FALSE
);
ALTER TABLE toplink.obb_bilateral_quota
  OWNER TO ue6lindbc6iam1;

-- Constraint: toplink.obb_bilateral_quota_pkey

-- ALTER TABLE toplink.obb_bilateral_quota DROP CONSTRAINT obb_bilateral_quota_pkey;

ALTER TABLE toplink.obb_bilateral_quota
  ADD CONSTRAINT obb_bilateral_quota_pkey PRIMARY KEY(id); 
  
-- Index: toplink.access_id_user_idx

-- DROP INDEX toplink.access_id_user_idx;

CREATE INDEX access_id_user_idx
  ON toplink.access
  USING btree
  (id_user);


-- Table: toplink.settings

-- DROP TABLE toplink.settings;

CREATE TABLE toplink.settings
(
  lastupdatets timestamp without time zone DEFAULT now(),
  key character varying(255),
  value character varying(255),
  id serial NOT NULL,
  CONSTRAINT settings_pkey PRIMARY KEY (id),
  CONSTRAINT settings_key_key UNIQUE (key)
)
WITH (
  OIDS=FALSE
);
ALTER TABLE toplink.settings
  OWNER TO ue6lindbc6iam1;
  

-- Table: toplink.user_sessions

-- DROP TABLE toplink.user_sessions;

CREATE TABLE toplink.user_sessions (
    session_id VARCHAR(128) NOT NULL PRIMARY KEY,
    session_value BYTEA NOT NULL,
    session_time INTEGER NOT NULL,
    sess_lifetime INTEGER NOT NULL
);

  
-- Table: toplink.access_logs

-- DROP TABLE toplink.access_logs;

CREATE TABLE toplink.access_logs
(
  id serial NOT NULL,
  dyno character varying(32),
  method character varying(16),
  ip character varying(32),
  uri character varying(1024),
  username character varying(32),
  date timestamp without time zone DEFAULT now()
)
WITH (
  OIDS=FALSE
);

-- Table: toplink.regcache

-- DROP TABLE toplink.regcache;

CREATE TABLE toplink.regcache
(
  account_sfid character varying(18) NOT NULL,
  event_sfid character varying(18) NOT NULL,
  id serial NOT NULL,
  json text,
  date_add integer,
  CONSTRAINT regcache_pkey PRIMARY KEY (id),
  CONSTRAINT regcache_event_sfid_account_sfid_key UNIQUE (event_sfid, account_sfid)
)
WITH (
  OIDS=FALSE
);

CREATE INDEX regcache_account_sfid_event_sfid_idx
  ON toplink.regcache
  USING btree
  (account_sfid COLLATE pg_catalog."default", event_sfid COLLATE pg_catalog."default");



  
-- HEROKU CONNECT SECTION
  
  
-- Index: salesforcetraining2.programme__c_category__c_idx

-- DROP INDEX salesforcetraining2.programme__c_category__c_idx;

CREATE INDEX programme__c_category__c_idx
  ON salesforcetraining2.programme__c
  USING btree
  (category__c COLLATE pg_catalog."default");

-- Index: salesforcetraining2.programme__c_event__c_idx

-- DROP INDEX salesforcetraining2.programme__c_event__c_idx;

CREATE INDEX programme__c_event__c_idx
  ON salesforcetraining2.programme__c
  USING btree
  (event__c COLLATE pg_catalog."default");

-- Index: salesforcetraining2.session__c_event__c_id_idx

-- DROP INDEX salesforcetraining2.session__c_event__c_id_idx;

CREATE INDEX session__c_event__c_id_idx
  ON salesforcetraining2.session__c
  USING btree
  (event__c_id);
  
-- Index: salesforcetraining2.session__c_primary_programme__c_idx

-- DROP INDEX salesforcetraining2.session__c_primary_programme__c_idx;

CREATE INDEX session__c_primary_programme__c_idx
  ON salesforcetraining2.session__c
  USING btree
  (primary_programme__c COLLATE pg_catalog."default");

-- Index: salesforcetraining2.session__c_status__c_idx

-- DROP INDEX salesforcetraining2.session__c_status__c_idx;

CREATE INDEX session__c_status__c_idx
  ON salesforcetraining2.session__c
  USING btree
  (status__c COLLATE pg_catalog."default");

-- Index: salesforcetraining2.session__c_type__c_idx

-- DROP INDEX salesforcetraining2.session__c_type__c_idx;

CREATE INDEX session__c_type__c_idx
  ON salesforcetraining2.session__c
  USING btree
  (type__c COLLATE pg_catalog."default");  
  
-- Index: salesforcetraining2.position__c_top_level_organization__c_idx

-- DROP INDEX salesforcetraining2.position__c_top_level_organization__c_idx;

CREATE INDEX position__c_top_level_organization__c_idx
  ON salesforcetraining2.position__c
  USING btree
  (top_level_organization__c COLLATE pg_catalog."default");

-- Index: salesforcetraining2.opportunity_accountid_idx

-- DROP INDEX salesforcetraining2.opportunity_accountid_idx;

CREATE INDEX opportunity_accountid_idx
  ON salesforcetraining2.opportunity
  USING btree
  (accountid COLLATE pg_catalog."default");
  
-- Index: salesforcetraining2.opportunity_event__c_id_idx

-- DROP INDEX salesforcetraining2.opportunity_event__c_id_idx;

CREATE INDEX opportunity_event__c_id_idx
  ON salesforcetraining2.opportunity
  USING btree
  (event__c_id);

-- Index: salesforcetraining2.opportunity_stagename_idx

-- DROP INDEX salesforcetraining2.opportunity_stagename_idx;

CREATE INDEX opportunity_stagename_idx
  ON salesforcetraining2.opportunity
  USING btree
  (stagename COLLATE pg_catalog."default");

-- Index: salesforcetraining2.opportunity_position__c_idx

-- DROP INDEX salesforcetraining2.opportunity_position__c_idx;

CREATE INDEX opportunity_position__c_idx
  ON salesforcetraining2.opportunity
  USING btree
  (position__c COLLATE pg_catalog."default");
  
-- Index: salesforcetraining2.role__c_constituent__c_idx

-- DROP INDEX salesforcetraining2.role__c_constituent__c_idx;

CREATE INDEX role__c_constituent__c_idx
  ON salesforcetraining2.role__c
  USING btree
  (constituent__c COLLATE pg_catalog."default");
  
-- Index: salesforcetraining2.role__c_type__c_idx

-- DROP INDEX salesforcetraining2.role__c_type__c_idx;

CREATE INDEX role__c_type__c_idx
  ON salesforcetraining2.role__c
  USING btree
  (type__c COLLATE pg_catalog."default");
  
-- Index: salesforcetraining2.role__c_event__c_idx

-- DROP INDEX salesforcetraining2.role__c_event__c_idx;

CREATE INDEX role__c_event__c_idx
  ON salesforcetraining2.role__c
  USING btree
  (event__c COLLATE pg_catalog."default");

-- Index: salesforcetraining2.role__c_eventopportunity__c_idx

-- DROP INDEX salesforcetraining2.role__c_eventopportunity__c_idx;

CREATE INDEX role__c_eventopportunity__c_idx
  ON salesforcetraining2.role__c
  USING btree
  (eventopportunity__c COLLATE pg_catalog."default");

-- Index: salesforcetraining2.role__c_session__c_idx

-- DROP INDEX salesforcetraining2.role__c_session__c_idx;

CREATE INDEX role__c_session__c_idx
  ON salesforcetraining2.role__c
  USING btree
  (session__c COLLATE pg_catalog."default");

-- Index: salesforcetraining2.role__c_sessionstatus__c_idx

-- DROP INDEX salesforcetraining2.role__c_sessionstatus__c_idx;

CREATE INDEX role__c_sessionstatus__c_idx
  ON salesforcetraining2.role__c
  USING btree
  (sessionstatus__c COLLATE pg_catalog."default");

-- Index: salesforcetraining2.role__c_status__c_idx

-- DROP INDEX salesforcetraining2.role__c_status__c_idx;

CREATE INDEX role__c_status__c_idx
  ON salesforcetraining2.role__c
  USING btree
  (status__c COLLATE pg_catalog."default");

-- Index: salesforcetraining2.role__c_tech_primaryprogrammeid__c_idx

-- DROP INDEX salesforcetraining2.role__c_tech_primaryprogrammeid__c_idx;

CREATE INDEX role__c_tech_primaryprogrammeid__c_idx
  ON salesforcetraining2.role__c
  USING btree
  (tech_primaryprogrammeid__c COLLATE pg_catalog."default");
  
-- Index: salesforcetraining2.programmeroomsetup__c_room_name__c_idx

-- DROP INDEX salesforcetraining2.programmeroomsetup__c_room_name__c_idx;

CREATE INDEX programmeroomsetup__c_room_name__c_idx
  ON salesforcetraining2.programmeroomsetup__c
  USING btree
  (room_name__c COLLATE pg_catalog."default");

