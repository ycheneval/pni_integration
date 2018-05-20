-- Function: salesforcetraining2.obb_sel_timeslots(character, integer)

-- DROP FUNCTION salesforcetraining2.obb_sel_timeslots(character, integer);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_sel_timeslots(IN event_id character, IN duration integer)
  RETURNS TABLE(day character varying, start_time timestamp without time zone, end_time timestamp without time zone) AS
$BODY$
DECLARE
/*
    Author: YCH
    History: V1.0  Jul 02, 2015: Creation
		V1.1 Aug 25, Added _slot_increment
                V1.2 Sep 02, Make sure duration is used in the endslot
		V1.3 Sep 18, Set _slot_increment to 20 is duration = 20
                V1.4 Nov 13, Added condition OR ba.isdeleted IS NULL to take into account bilateral_availability__c
                             rows that are not synced to Salesforce yet
*/
	_start_date timestamp;
	_start_date_endslot timestamp;
	_end_date timestamp;
	_availability record;
	_slot_increment integer := 15;
BEGIN
	IF duration = 20 THEN
		_slot_increment := 20;
	END IF;
	
	DROP table IF EXISTS _temp_timeslots_general;
	CREATE temporary TABLE _temp_timeslots_general (
		cur_day character varying(11),
		start_time timestamp,
		end_time timestamp
	);
	-- Calculate all possible start time based on duration of each meeting
	FOR _availability IN
		SELECT date__c, start_time__c, end_time__c FROM bilateral_availability__c ba
		INNER JOIN Programme__c p ON ba.programme_name__c = p.sfid
		WHERE LEFT(p.event__c, 15) = LEFT(event_id, 15)
                AND ba.isdeleted = FALSE OR ba.isdeleted IS NULL
		AND p.status__c IN ('Open')
		ORDER BY (ba.date__c || ' ' || ba.start_time__c)::timestamp
	LOOP
		_start_date = _availability.date__c || ' ' || _availability.start_time__c;
		_end_date := (_availability.date__c || ' ' || _availability.end_time__c)::timestamp;
		/* All meeting can start every 'duration' minutes */
		WHILE _start_date < _end_date LOOP
			_start_date_endslot = _start_date + duration * INTERVAL '1 minute';
			INSERT INTO _temp_timeslots_general (cur_day, start_time, end_time) SELECT to_char(_start_date, 'YYYY-MM-DD'), _start_date, _start_date_endslot;
			_start_date := _start_date + _slot_increment * INTERVAL '1 minute';
		END LOOP;
	END LOOP;


	RETURN QUERY SELECT * from _temp_timeslots_general order by start_time;
END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 1000
  ROWS 100;
ALTER FUNCTION salesforcetraining2.obb_sel_timeslots(character, integer)
  OWNER TO ue6lindbc6iam1;
COMMENT ON FUNCTION salesforcetraining2.obb_sel_timeslots(character, integer) IS 'TBB Timeslots for Event (without taking occupancy or room type into account)';

-- SET search_path = salesforcetraining2, public;
-- SELECT * from obb_sel_timeslots('a0Pb0000000GePmEAK', 15);
