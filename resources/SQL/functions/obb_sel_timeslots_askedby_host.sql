-- Function: salesforcetraining2.obb_sel_timeslots_askedby_host(character, integer)

-- DROP FUNCTION salesforcetraining2.obb_sel_timeslots_askedby_host(character, integer);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_sel_timeslots_askedby_host(IN session_id character, IN duration integer)
  RETURNS TABLE(day character varying, start_time timestamp without time zone, end_time timestamp without time zone) AS
$BODY$
DECLARE
/*
    Author: YCH
    History: V1.0  Jul 02, 2015: Creation
		V1.1 Aug 25, Added _slot_increment
                V1.2 Sep 02, Make sure duration is used in the endslot
                V1.3 Nov 13, Added condition OR ba.isdeleted IS NULL to take into account bilateral_availability__c
                             rows that are not synced to Salesforce yet
*/
	_start_date timestamp;
	_start_date_endslot timestamp;
	_end_date timestamp;
	_availability record;
	_time_slot record;
	event_id character varying(18);
	_slot_increment integer := 15;
	
BEGIN
	IF duration = 20 THEN
		_slot_increment := 20;
	END IF;
	
	event_id := (SELECT event__c FROM Session__c se WHERE se.sfid = session_id);

	DROP table IF EXISTS _temp_timeslots_byhost;
	CREATE temporary TABLE  _temp_timeslots_byhost (
		cur_day character varying(11),
		start_time timestamp,
		end_time timestamp
	);
	-- Calculate all possible start time based on duration of each meeting
	FOR _availability IN
		SELECT date__c, start_time__c, end_time__c FROM bilateral_availability__c ba
		INNER JOIN Session__c se ON ba.session_name__c = se.sfid
		INNER JOIN Programme__c p ON se.primary_programme__c = p.sfid
		WHERE ba.session_name__c = session_id
                AND ba.isdeleted = FALSE OR ba.isdeleted IS NULL
		AND p.status__c IN ('Open')
		AND p.isdeleted = FALSE
		ORDER BY (ba.date__c || ' ' || ba.start_time__c)::timestamp
	LOOP
		_start_date = (_availability.date__c || ' ' || _availability.start_time__c)::timestamp;
		_end_date := (_availability.date__c || ' ' || _availability.end_time__c)::timestamp;
		/* All meeting can start every 'duration' minutes */
/*
		WHILE _start_date < _end_date LOOP
			_start_date_endslot = _start_date + duration * INTERVAL '1 minute';
			INSERT INTO _temp_timeslots_byhost (cur_day, start_time, end_time) SELECT to_char(_start_date, 'YYYY-MM-DD'), _start_date, _start_date_endslot;
			_start_date := _start_date + _slot_increment * INTERVAL '1 minute';
		END LOOP;
*/
		INSERT INTO _temp_timeslots_byhost (cur_day, start_time, end_time) SELECT to_char(_start_date, 'YYYY-MM-DD'), _start_date, _end_date;	END LOOP;


	RETURN QUERY SELECT * from _temp_timeslots_byhost order by start_time;
END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 1000
  ROWS 100;
ALTER FUNCTION salesforcetraining2.obb_sel_timeslots_askedby_host(character, integer)
  OWNER TO ue6lindbc6iam1;
COMMENT ON FUNCTION salesforcetraining2.obb_sel_timeslots_askedby_host(character, integer) IS 'TBB Timeslots asked by host for a specific session';

-- SET search_path = salesforcetraining2, public;
-- SELECT * from salesforcetraining2.obb_sel_timeslots_askedby_host('a0Wb0000002lQimEAE', 15);
